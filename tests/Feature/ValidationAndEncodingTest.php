<?php

declare(strict_types=1);

/**
 * Exercises malformed declarations and payload boundaries with small local fixtures.
 *
 * The deliberately invalid step classes below isolate one unsupported signature at
 * a time, such as reference parameters or a static handle(). Encoding cases inspect
 * strict restored types and registered aliases. Temporary alternate connections
 * prove explicit model overrides differ from preserving a model-declared connection.
 */

use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Scarlett\Definition\Node;
use Scarlett\Definition\Subject;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Context;
use Scarlett\Execution\Invoker;
use Scarlett\Inputs\ModelReference;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Step;
use Scarlett\Tests\Fixtures\Amount;
use Scarlett\Tests\Fixtures\ConstructionTrap;
use Scarlett\Tests\Fixtures\CountingAmountMapper;
use Scarlett\Tests\Fixtures\EchoStep;
use Scarlett\Tests\Fixtures\EnumStep;
use Scarlett\Tests\Fixtures\IntPriority;
use Scarlett\Tests\Fixtures\InvalidUtf8Status;
use Scarlett\Tests\Fixtures\InvokerTypesStep;
use Scarlett\Tests\Fixtures\SourceRecord;
use Scarlett\Tests\Fixtures\StringStatus;

it('rejects malformed workflow definitions and invalid step signatures', function (callable $factory, string $message) {
    expect(fn () => $factory()->validate())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty nodes' => [fn () => new Workflow([]), 'at least one step'],
    'non-node entry' => [fn () => new Workflow(['not-a-node']), 'non-empty identity'],
    'empty key' => [fn () => new Workflow([new Node('', EchoStep::class)]), 'non-empty identity'],
    'non-step class' => [fn () => new Workflow([new Node('a', stdClass::class)]), 'concrete Scarlett step'],
    'abstract step class' => [fn () => new Workflow([new Node('a', AbstractInvalidTestStep::class)]), 'concrete Scarlett step'],
    'missing handle' => [fn () => new Workflow([new Node('a', NoHandleInvalidTestStep::class)]), 'concrete Scarlett step'],
    'non-public handle' => [fn () => new Workflow([new Node('a', ProtectedHandleInvalidTestStep::class)]), 'public and non-static'],
    'static handle' => [fn () => new Workflow([new Node('a', StaticHandleInvalidTestStep::class)]), 'public and non-static'],
    'parent and rescues together' => [fn () => new Workflow([
        new Node('root', EchoStep::class),
        new Node('child', EchoStep::class, parent: 'root', rescues: 'root'),
    ]), 'recovery root'],
    'unknown parent' => [fn () => new Workflow([new Node('a', EchoStep::class, parent: 'missing')]), 'Unknown ancestor'],
    'unknown rescues' => [fn () => new Workflow([new Node('a', EchoStep::class, rescues: 'missing')]), 'Unknown ancestor'],
    'rescues cycle' => [fn () => new Workflow([
        new Node('a', EchoStep::class, rescues: 'b'),
        new Node('b', EchoStep::class, rescues: 'a'),
    ]), 'Cycle'],
    'by-reference param' => [fn () => new Workflow([new Node('a', ByReferenceInvalidTestStep::class, inputs: ['value' => 1])]), 'passed by reference'],
    'variadic context' => [fn () => new Workflow([new Node('a', VariadicContextInvalidTestStep::class)]), 'cannot be bound or variadic'],
    'non-list variadic literal' => [fn () => new Workflow([new Node('a', VariadicLiteralInvalidTestStep::class, inputs: ['values' => ['k' => 1]])]), 'requires a list'],
]);

it('invokes optional defaults, mixed, iterable, callable, literal bools and intersection types', function () {
    $invoker = app(Invoker::class);
    $inputs = [
        'anything' => ['k' => 'v'],
        'items' => new ArrayObject(['a', 'b']),
        'callback' => fn (): string => 'called',
        'alwaysTrue' => true,
        'alwaysFalse' => false,
        'intersection' => new ArrayObject([1, 2]),
    ];

    (new Workflow([new Node('types', InvokerTypesStep::class, inputs: [
        'anything' => ['k' => 'v'],
        'items' => ['a', 'b'],
        'callback' => fn (): string => 'called',
        'alwaysTrue' => true,
        'alwaysFalse' => false,
        'intersection' => new ArrayObject([1, 2]),
    ])]))->validate();

    $step = $invoker->construct(InvokerTypesStep::class, $inputs);
    $result = $invoker->handle($step, $inputs);

    expect($result)->toBe(['default-ctor', ['k' => 'v'], ['a', 'b'], 'called', true, false, [1, 2], 99]);
});

it('rejects constructing or handling a context-dependent step without an execution context', function () {
    $invoker = app(Invoker::class);
    expect(fn () => $invoker->construct(ContextRequiredInvalidTestStep::class, []))->toThrow(InvalidArgumentException::class, 'context is unavailable');
    expect(fn () => $invoker->handle(new ContextRequiredHandleInvalidTestStep, []))->toThrow(InvalidArgumentException::class, 'context is unavailable');
});

it('rejects empty or invalid UTF-8 Subject and SubjectSlot identities', function (string $type, string $id, string $name) {
    expect(fn () => new Subject($type, $id))->toThrow(InvalidArgumentException::class);
    expect(fn () => new SubjectSlot($type, $id, $name))->toThrow(InvalidArgumentException::class);
})->with([
    'empty type' => ['', '1', 'current'],
    'empty id' => ['doc', '', 'current'],
    'invalid utf8 type' => ["\xFF", '1', 'current'],
    'invalid utf8 id' => ['doc', "\xFF", 'current'],
]);

it('rejects empty or invalid UTF-8 SubjectSlot name', function (string $name) {
    expect(fn () => new SubjectSlot('doc', '1', $name))->toThrow(InvalidArgumentException::class);
})->with(['empty name' => [''], 'invalid utf8 name' => ["\xFF"]]);

it('round trips registered string and integer BackedEnums and rejects unregistered or unknown aliases', function () {
    config([
        'scarlett.enums' => [
            'string-status' => StringStatus::class,
            'int-priority' => IntPriority::class,
        ],
    ]);

    $flow = app(Engine::class)->start(new Workflow([
        new Node('enums', EnumStep::class, inputs: [
            'status' => StringStatus::Done,
            'priority' => IntPriority::High,
        ]),
    ]));

    expect($flow->fresh()->status)->toBe('completed');
    $decoded = app(PayloadCodec::class)->decode($flow->steps()->sole()->result);
    expect($decoded)->toBe(['status' => StringStatus::Done, 'priority' => IntPriority::High]);

    config(['scarlett.enums' => []]);
    expect(fn () => app(PayloadCodec::class)->encode(StringStatus::Pending))->toThrow(InvalidArgumentException::class, 'Unsupported');
    expect(fn () => app(PayloadCodec::class)->decode(['version' => 2, 'value' => ['type' => 'enum', 'alias' => 'missing', 'data' => 'done']]))
        ->toThrow(InvalidArgumentException::class, 'enums alias');
});

it('rejects non-finite floats, unknown payload node types and invalid model alias configurations', function () {
    $codec = app(PayloadCodec::class);

    foreach ([NAN, INF, -INF] as $nonFinite) {
        expect(fn () => $codec->encode($nonFinite))->toThrow(InvalidArgumentException::class, 'Non-finite');
    }

    expect(fn () => $codec->decode(['version' => 2, 'value' => ['type' => 'unknown-node-type']]))
        ->toThrow(InvalidArgumentException::class, 'Unknown Scarlett payload type');

    expect(fn () => $codec->decode(['version' => 2, 'value' => ['type' => 'model', 'alias' => 'missing', 'id' => 1, 'connection' => null]]))
        ->toThrow(InvalidArgumentException::class, 'models alias');

    config(['scarlett.models' => ['not-a-model' => stdClass::class]]);
    expect(fn () => $codec->decode(['version' => 2, 'value' => ['type' => 'model', 'alias' => 'not-a-model', 'id' => 1, 'connection' => null]]))
        ->toThrow(InvalidArgumentException::class, 'Eloquent models');
});

it('reports malformed payload nodes and unsupported resources without resolving mappers', function () {
    $codec = app(PayloadCodec::class);
    config(['scarlett.payload_mappers' => ['invalid' => stdClass::class]]);
    $resource = fopen('php://memory', 'rb');

    try {
        expect(fn () => $codec->encode($resource))
            ->toThrow(InvalidArgumentException::class, 'Unsupported payload type: resource');
        expect(fn () => $codec->decode(['version' => 2, 'value' => []]))
            ->toThrow(InvalidArgumentException::class, 'payload node');
        expect(fn () => $codec->decode(['version' => 2, 'value' => ['type' => 'model', 'id' => 1, 'connection' => null]]))
            ->toThrow(InvalidArgumentException::class, 'payload node');
    } finally {
        fclose($resource);
    }
});

it('rejects malformed payload values before restoring them', function (array $payload, string $message) {
    expect(fn () => app(PayloadCodec::class)->decode($payload))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'missing value' => [['version' => 2], 'payload node'],
    'non-array value' => [['version' => 2, 'value' => 'text'], 'payload node'],
    'scalar holding an array' => [['version' => 2, 'value' => ['type' => 'scalar', 'data' => [1]]], 'payload node'],
    'float from text' => [['version' => 2, 'value' => ['type' => 'float', 'data' => 'abc']], 'payload node'],
    'array holding a scalar member' => [['version' => 2, 'value' => ['type' => 'array', 'data' => [123]]], 'payload node'],
    'collection of a scalar' => [['version' => 2, 'value' => ['type' => 'collection', 'data' => ['type' => 'scalar', 'data' => 'oops']]], 'payload node'],
    'date from an integer' => [['version' => 2, 'value' => ['type' => 'date', 'data' => 123]], 'payload node'],
    'date from unparseable text' => [['version' => 2, 'value' => ['type' => 'date', 'data' => 'not a date']], 'payload node'],
    'enum with an array alias' => [['version' => 2, 'value' => ['type' => 'enum', 'alias' => [], 'data' => 'done']], 'payload node'],
    'mapped node with scalar data' => [['version' => 2, 'value' => ['type' => 'mapped', 'alias' => 'amount', 'data' => 'bad']], 'payload node'],
    'model with an array identifier' => [['version' => 2, 'value' => ['type' => 'model', 'alias' => 'source', 'id' => [1, 2], 'connection' => null]], 'payload node'],
]);

it('rejects configured classes that do not qualify before constructing them', function (array $payload, string $message) {
    config([
        'scarlett.models' => ['trap' => ConstructionTrap::class],
        'scarlett.payload_mappers' => ['trap' => ConstructionTrap::class],
    ]);

    expect(fn () => app(PayloadCodec::class)->decode($payload))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'model alias to a non-model class' => [['version' => 2, 'value' => ['type' => 'model', 'alias' => 'trap', 'id' => 1, 'connection' => null]], 'Eloquent models'],
    'mapped alias to a non-mapper class' => [['version' => 2, 'value' => ['type' => 'mapped', 'alias' => 'trap', 'data' => ['type' => 'scalar', 'data' => 1]]], 'PayloadMapper'],
]);

it('restores well-formed payload nodes of every type unchanged', function () {
    $codec = app(PayloadCodec::class);
    $payload = ['version' => 2, 'value' => ['type' => 'array', 'data' => [
        'float' => ['type' => 'float', 'data' => 2],
        'scalar' => ['type' => 'scalar', 'data' => 'text'],
        'nothing' => ['type' => 'scalar', 'data' => null],
        'collection' => ['type' => 'collection', 'data' => ['type' => 'array', 'data' => [['type' => 'scalar', 'data' => 'oops']]]],
        'date' => ['type' => 'date', 'data' => '2026-11-22T10:00:00.000000+00:00', 'timezone' => 'UTC'],
    ]]];

    $decoded = $codec->decode($payload);

    expect($decoded['float'])->toBe(2.0)
        ->and($decoded['scalar'])->toBe('text')
        ->and($decoded['nothing'])->toBeNull()
        ->and($decoded['collection'])->toBeInstanceOf(Collection::class)
        ->and($decoded['collection']->all())->toBe(['oops'])
        ->and($decoded['date'])->toBeInstanceOf(DateTimeImmutable::class);
});

it('rejects malformed UTF-8 text and keys at encode time', function (mixed $value) {
    expect(fn () => app(PayloadCodec::class)->encode($value))->toThrow(InvalidArgumentException::class, 'valid UTF-8');
})->with([
    'invalid UTF-8 string' => ["\xB1"],
    'invalid UTF-8 nested string' => [['label' => "caf\xE9"]],
    'invalid UTF-8 array key' => [["\xB1" => 'value']],
]);

it('rejects arrays that contain themselves instead of recursing without bound', function () {
    $value = [];
    $value[] = &$value;

    expect(fn () => app(PayloadCodec::class)->encode($value))->toThrow(InvalidArgumentException::class, 'nested too deeply');
});

it('encodes valid UTF-8 strings and keys unchanged', function () {
    $codec = app(PayloadCodec::class);
    $value = ['名前' => 'café', 'nested' => [['label' => 'naïve']]];

    expect($codec->decode($codec->encode($value)))->toBe($value);
});

it('refuses custom objects when payload mappers are not configured as an array', function () {
    config(['scarlett.payload_mappers' => null]);

    expect(fn () => app(PayloadCodec::class)->encode(new Amount(1)))
        ->toThrow(InvalidArgumentException::class, 'scarlett.payload_mappers');
});

it('restores a ModelReference using its explicit database connection', function () {
    $altPath = tempnam(sys_get_temp_dir(), 'scarlett-model-conn-');
    try {
        config([
            'database.connections.alt_source' => ['driver' => 'sqlite', 'database' => $altPath, 'prefix' => ''],
            'scarlett.models' => ['source' => SourceRecord::class],
        ]);
        Schema::connection('alt_source')->create('source_records', fn (Blueprint $table) => [$table->id(), $table->string('name')]);
        $id = DB::connection('alt_source')->table('source_records')->insertGetId(['name' => 'from-alt']);

        $codec = app(PayloadCodec::class);
        $encoded = $codec->encode(new ModelReference('source', $id, connection: 'alt_source'));
        $restored = $codec->decode($encoded);

        expect($restored)->toBeInstanceOf(SourceRecord::class)
            ->and($restored->name)->toBe('from-alt')
            ->and($restored->getConnectionName())->toBe('alt_source');
    } finally {
        DB::purge('alt_source');
        if (is_file($altPath)) {
            unlink($altPath);
        }
    }
});

it('restores a ModelReference using the model declared connection when no override is supplied', function () {
    $altPath = tempnam(sys_get_temp_dir(), 'scarlett-model-default-');
    try {
        config([
            'database.connections.alt_source' => ['driver' => 'sqlite', 'database' => $altPath, 'prefix' => ''],
            'scarlett.models' => ['custom-source' => CustomConnectionSourceRecord::class],
        ]);
        Schema::connection('alt_source')->create('source_records', fn (Blueprint $table) => [$table->id(), $table->string('name')]);
        $id = DB::connection('alt_source')->table('source_records')->insertGetId(['name' => 'from-declared-connection']);

        $codec = app(PayloadCodec::class);
        $encoded = $codec->encode(new ModelReference('custom-source', $id));
        $restored = $codec->decode($encoded);

        expect($restored)->toBeInstanceOf(CustomConnectionSourceRecord::class)
            ->and($restored->name)->toBe('from-declared-connection')
            ->and($restored->getConnectionName())->toBe('alt_source');
    } finally {
        DB::purge('alt_source');
        if (is_file($altPath)) {
            unlink($altPath);
        }
    }
});

abstract class AbstractInvalidTestStep extends Step
{
    public function handle(): void {}
}

class NoHandleInvalidTestStep extends Step {}

class ProtectedHandleInvalidTestStep extends Step
{
    protected function handle(): void {}
}

class StaticHandleInvalidTestStep extends Step
{
    public static function handle(): void {}
}

class ByReferenceInvalidTestStep extends Step
{
    public function handle(int &$value): void {}
}

class VariadicContextInvalidTestStep extends Step
{
    public function handle(Context ...$context): void {}
}

class VariadicLiteralInvalidTestStep extends Step
{
    public function handle(int ...$values): void {}
}

class ContextRequiredInvalidTestStep extends Step
{
    public function __construct(Context $context) {}

    public function handle(): void {}
}

class ContextRequiredHandleInvalidTestStep extends Step
{
    public function handle(Context $context): void {}
}

class CustomConnectionSourceRecord extends SourceRecord
{
    protected $connection = 'alt_source';
}

it('resolves each configured payload mapper once per encode call', function () {
    CountingAmountMapper::$constructions = 0;
    config(['scarlett.payload_mappers' => ['amount' => CountingAmountMapper::class]]);
    $amounts = collect(range(1, 50))->map(fn (int $minorUnits): Amount => new Amount($minorUnits));

    $payload = app(PayloadCodec::class)->encode($amounts);

    expect(CountingAmountMapper::$constructions)->toBe(1)
        ->and(app(PayloadCodec::class)->decode($payload)->map->minorUnits->all())->toBe(range(1, 50));
});

it('rejects malformed UTF-8 in model references and enum values at encode time', function (Closure $value) {
    config(['scarlett.enums' => ['broken' => InvalidUtf8Status::class]]);

    expect(fn () => app(PayloadCodec::class)->encode($value()))->toThrow(InvalidArgumentException::class, 'valid UTF-8');
})->with([
    'model reference alias' => [fn () => new ModelReference("\xB1", 1)],
    'model reference string id' => [fn () => new ModelReference('record', "id-\xB1")],
    'model reference connection' => [fn () => new ModelReference('record', 1, "db-\xB1")],
    'enum backing value' => [fn () => InvalidUtf8Status::Broken],
]);

it('keeps valid model references and enum values encodable', function () {
    config(['scarlett.enums' => ['status' => StringStatus::class]]);
    $encoded = app(PayloadCodec::class)->encode([new ModelReference('record', 'id-é', 'db'), StringStatus::Done]);

    expect($encoded['value']['data'][0])->toBe(['type' => 'model', 'alias' => 'record', 'id' => 'id-é', 'connection' => 'db'])
        ->and($encoded['value']['data'][1])->toBe(['type' => 'enum', 'alias' => 'status', 'data' => 'done']);
});

it('stores nesting up to the readable JSON depth limit and refuses one level beyond it', function () {
    $nest = function (int $levels): array {
        $value = 'leaf';
        for ($level = 0; $level < $levels; $level++) {
            $value = [$value];
        }

        return $value;
    };
    $codec = app(PayloadCodec::class);
    $deepest = $nest(254);
    $stored = json_encode($codec->encode($deepest), JSON_THROW_ON_ERROR);

    expect($codec->decode(json_decode($stored, true, 512, JSON_THROW_ON_ERROR)))->toBe($deepest)
        ->and(fn () => $codec->encode($nest(255)))->toThrow(InvalidArgumentException::class, 'nested too deeply');
});
