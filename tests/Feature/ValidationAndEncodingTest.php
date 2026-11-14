<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
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
use Scarlett\Tests\Fixtures\EchoStep;
use Scarlett\Tests\Fixtures\EnumStep;
use Scarlett\Tests\Fixtures\IntPriority;
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
