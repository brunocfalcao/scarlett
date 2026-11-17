<?php

declare(strict_types=1);

/**
 * Checks descriptive labels and latest-invocation diagnostics without changing work.
 *
 * Reflection must not construct object defaults early. Actual constructor and handle
 * arguments are captured only for the owning action attempt; verification retries
 * preserve that snapshot. Cases include service descriptors, falsey values, unsupported
 * defaults, stale writes, runtime-child labels, and upgrades of already populated rows.
 */

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Scarlett\Definition\Node;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Signature;
use Scarlett\Inputs\FromResult;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Inputs\Service;
use Scarlett\Step;
use Scarlett\Tests\Fixtures\Amount;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\DateResultStep;
use Scarlett\Tests\Fixtures\EchoStep;

beforeEach(function () {
    Queue::fake();
    MetadataDefault::$constructed = 0;
    MetadataSignatureStep::$calls = 0;
});

it('stores optional labels without changing identities and preserves them through replacement', function () {
    $engine = app(Engine::class);
    $plain = $engine->start(new Workflow([new Node('plain', EchoStep::class)]));
    $slot = new SubjectSlot('document', '42', 'generation');
    $make = fn () => new Workflow([new Node('step', EchoStep::class, title: 'Normalize', description: "Line one\nLine two", canonical: 'normalize')], metadata: ['revision' => 1], title: 'Generate', description: 'Generate the document', canonical: 'document.generate');
    $first = $engine->replace($slot, $make);
    $second = $engine->replace($slot, $make);
    expect($second->id)->not->toBe($first->id)
        ->and($first->fresh()->status)->toBe('cancelled')
        ->and($second->fresh()->only(['title', 'description', 'canonical', 'metadata']))->toBe(['title' => 'Generate', 'description' => 'Generate the document', 'canonical' => 'document.generate', 'metadata' => ['revision' => 1]])
        ->and($second->steps()->sole()->only(['key', 'title', 'description', 'canonical']))->toBe(['key' => 'step', 'title' => 'Normalize', 'description' => "Line one\nLine two", 'canonical' => 'normalize'])
        ->and($plain->fresh()->only(['title', 'description', 'canonical']))->toBe(['title' => null, 'description' => null, 'canonical' => null])
        ->and($plain->steps()->sole()->arguments)->toBeNull();
});

it('records signatures without constructing defaults and captures actual defaults context services and variadics once', function (array $values) {
    app()->instance(MetadataService::class, new MetadataService);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('step', MetadataSignatureStep::class, inputs: ['service' => new Service(MetadataService::class), 'values' => $values]),
        new Node('untouched', EchoStep::class),
    ]));
    $step = $flow->steps()->where('key', 'step')->sole();
    expect(MetadataDefault::$constructed)->toBe(0)
        ->and($step->arguments)->toBeNull()
        ->and($step->has_result)->toBeFalse();
    $expectedDefault = ['position' => 0, 'type' => MetadataDefault::class, 'optional' => true, 'variadic' => false, 'context' => false];
    $expectedHandle = ['parameters' => [
        'context' => ['position' => 0, 'type' => Context::class, 'optional' => false, 'variadic' => false, 'context' => true],
        'service' => ['position' => 1, 'type' => MetadataService::class, 'optional' => false, 'variadic' => false, 'context' => false],
        'values' => ['position' => 2, 'type' => 'string', 'optional' => true, 'variadic' => true, 'context' => false],
    ], 'return_type' => 'array'];
    expect(Arr::sortRecursive($step->signature['constructor']['parameters']['default']))->toBe(Arr::sortRecursive($expectedDefault))
        ->and(Arr::sortRecursive($step->signature['handle']))->toBe(Arr::sortRecursive($expectedHandle));
    runStep($flow->id, 'step');
    $step->refresh();
    $codec = app(PayloadCodec::class);
    expect((string) $step->state)->toBe('completed')
        ->and(MetadataDefault::$constructed)->toBe(1)->and(MetadataSignatureStep::$calls)->toBe(1)
        ->and($step->arguments['attempt'])->toBe($step->attempt)
        ->and($step->arguments['constructor']['default'])->toBe(['kind' => 'unavailable', 'type' => MetadataDefault::class])
        ->and($codec->decode($step->arguments['constructor']['label']['payload']))->toBe('default label')
        ->and($step->arguments['handle']['context'])->toBe(['kind' => 'context'])
        ->and($step->arguments['handle']['service'])->toBe(['kind' => 'service', 'class' => MetadataService::class])
        ->and($codec->decode($step->arguments['handle']['values']['payload']))->toBe($values)
        ->and($codec->decode($step->result))->toBe(['default label', $values])
        ->and($flow->steps()->where('key', 'untouched')->sole()->arguments)->toBeNull();
    $snapshot = $step->arguments;
    runStep($flow->id, 'step');
    expect($step->fresh()->arguments)->toBe($snapshot)->and(MetadataSignatureStep::$calls)->toBe(1);
})->with([[[]], [['one', 'two']]]);

it('records resolved prerequisite values and preserves empty and falsey results', function (mixed $value) {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('source', EchoStep::class, inputs: ['value' => $value]),
        new Node('target', EchoStep::class, stage: 1, inputs: ['value' => new FromResult('source')]),
    ]));
    $target = $flow->steps()->where('key', 'target')->sole();
    expect($target->arguments)->toBeNull()->and($target->has_result)->toBeFalse();
    runStep($flow->id, 'source');
    runStep($flow->id, 'target');
    $target->refresh();
    expect($target->inputs['value'])->toBe(['kind' => 'result', 'step' => 'source'])
        ->and(app(PayloadCodec::class)->decode($target->arguments['handle']['value']['payload']))->toBe($value)
        ->and($target->has_result)->toBeTrue()
        ->and(app(PayloadCodec::class)->decode($target->result))->toBe($value)
        ->and($flow->fresh()->status)->toBe('completed');
    expect(Schema::hasColumn('scarlett_workflows', 'result'))->toBeFalse();
})->with([null, false, 0, '', [[]], [['answer' => 42]]]);

it('preserves the action argument snapshot across verification retries', function () {
    $this->freezeSecond();
    DateResultStep::$verifiedTypes = [];
    $flow = app(Engine::class)->start(new Workflow([new Node('date', DateResultStep::class, inputs: ['name' => 'date'])]));
    $step = $flow->steps()->sole();
    expect($step->arguments)->toBeNull();
    runStep($flow->id, 'date');
    $snapshot = $step->fresh()->arguments;
    expect((string) $step->fresh()->state)->toBe('queued');
    $this->travel(5)->seconds();
    runStep($flow->id, 'date');
    expect((string) $step->fresh()->state)->toBe('completed')->and($step->fresh()->arguments)->toBe($snapshot);
});

it('rejects obsolete argument writes after cancellation', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([new Node('step', EchoStep::class)]));
    $step = $flow->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    $record = app(Signature::class)->recorder($step, new Context($flow->id, $step->id, $step->attempt, 0));
    expect($step->arguments)->toBeNull();
    $engine->cancelWorkflow($flow->id);
    expect(fn () => $record(new ReflectionMethod(EchoStep::class, 'handle'), ['obsolete']))->toThrow(RuntimeException::class, 'Attempt lost ownership');
    expect($step->fresh()->arguments)->toBeNull()->and($flow->fresh()->status)->toBe('cancelled');
});

it('preserves labels on qualified runtime children', function () {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([new Node('parent', EchoStep::class)]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    expect($flow->steps()->count())->toBe(1);
    $engine->decide($parent->id, $parent->attempt, Decision::children([
        new Node('child', EchoStep::class, title: 'Child', description: 'Child description', canonical: 'child.action'),
    ], resultFrom: 'child'));
    $child = $flow->steps()->where('parent_key', 'parent')->sole();
    expect($child->key)->toBe($parent->id.'/child')
        ->and($child->only(['title', 'description', 'canonical']))->toBe(['title' => 'Child', 'description' => 'Child description', 'canonical' => 'child.action'])
        ->and($child->signature['handle']['return_type'])->toBe('mixed');
});

it('upgrades existing rows and tolerates repeated migration and rollback without losing engine data', function () {
    $flow = app(Engine::class)->start(new Workflow([new Node('step', EchoStep::class)]));
    $step = $flow->steps()->sole();
    $migration = require __DIR__.'/../../database/migrations/2026_11_14_193248_add_workflow_and_step_descriptions.php';
    $migration->down();
    $old = (array) DB::table('scarlett_steps')->where('id', $step->id)->sole();
    expect(Schema::hasColumn('scarlett_steps', 'signature'))->toBeFalse();
    $migration->up();
    $migration->up();
    expect($step->fresh()->signature)->toBeNull()->and($step->fresh()->arguments)->toBeNull();
    $migration->down();
    expect((array) DB::table('scarlett_steps')->where('id', $step->id)->sole())->toBe($old);
    $migration->up();
    runStep($flow->id, 'step');
    expect($flow->fresh()->status)->toBe('completed')->and($step->fresh()->has_result)->toBeTrue();
});

it('replaces snapshots on action retries without retaining arguments from an older attempt', function () {
    $this->freezeSecond();
    config(['scarlett.retry.backoff_seconds' => [0]]);
    $flow = app(Engine::class)->start(new Workflow([controlled('retry', 'throw')]));
    $step = $flow->steps()->sole();
    runStep($flow->id, 'retry');
    $first = $step->fresh()->arguments;
    expect($first)->toHaveKeys(['attempt', 'constructor', 'handle'])
        ->and($step->fresh()->attempt)->not->toBe($first['attempt']);
    $nextAttempt = $step->fresh()->attempt;
    runStep($flow->id, 'retry');
    expect($step->fresh()->arguments['attempt'])->toBe($nextAttempt)
        ->and($step->fresh()->arguments['constructor'])->toBe($first['constructor'])
        ->and($step->fresh()->has_result)->toBeFalse();
});

it('does not claim that handle ran when before settled the step', function () {
    $flow = app(Engine::class)->start(new Workflow([controlled('skip', 'skip')]));
    $step = $flow->steps()->sole();
    expect($step->arguments)->toBeNull();
    runStep($flow->id, 'skip');
    expect((string) $step->fresh()->state)->toBe('skipped')
        ->and($step->fresh()->arguments)->toHaveKeys(['attempt', 'constructor'])->not->toHaveKey('handle')
        ->and($step->fresh()->has_result)->toBeFalse();
});

it('records registered typed payloads without changing the action values', function () {
    config(['scarlett.payload_mappers' => ['amount' => AmountMapper::class]]);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('amount', EchoStep::class, inputs: ['value' => new Amount(125)]),
    ]));
    $step = $flow->steps()->sole();
    expect($step->arguments)->toBeNull();
    runStep($flow->id, 'amount');
    $step->refresh();
    $codec = app(PayloadCodec::class);
    expect($codec->decode($step->arguments['handle']['value']['payload'])->minorUnits)->toBe(125)
        ->and($codec->decode($step->result)->minorUnits)->toBe(125)
        ->and((string) $step->state)->toBe('completed');
});

it('rejects a snapshot after lease expiry or replacement without changing the newer snapshot', function (bool $replace) {
    $this->freezeSecond();
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([new Node('step', EchoStep::class)]));
    $step = $flow->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    $context = new Context($flow->id, $step->id, $step->attempt, 0);
    $record = app(Signature::class)->recorder($step, $context);
    $method = new ReflectionMethod(EchoStep::class, 'handle');
    $record($method, ['original']);
    $this->travel($step->executionOptions()['lease_seconds'] + 1)->seconds();
    if ($replace) {
        $engine->recover($step->id);
        $current = $step->fresh();
        $this->travelTo($current->available_at);
        $engine->claim($current->id, $current->attempt);
        app(Signature::class)->recorder($current, new Context($flow->id, $current->id, $current->attempt, 1))($method, ['replacement']);
    }
    $before = $step->fresh()->arguments;
    expect(app(PayloadCodec::class)->decode($before['handle']['value']['payload']))->toBe($replace ? 'replacement' : 'original');
    expect(fn () => $record($method, ['obsolete']))->toThrow(RuntimeException::class, 'Attempt lost ownership');
    expect($step->fresh()->arguments)->toBe($before);
})->with([false, true]);

it('keeps non-JSON defaults executable while explicitly marking their argument unavailable', function () {
    $flow = app(Engine::class)->start(new Workflow([new Node('invalid-utf8', MetadataBinaryDefaultStep::class)]));
    $step = $flow->steps()->sole();
    expect($step->arguments)->toBeNull();
    runStep($flow->id, 'invalid-utf8');
    expect((string) $step->fresh()->state)->toBe('completed')
        ->and($step->fresh()->arguments['handle']['value'])->toBe(['kind' => 'unavailable', 'type' => 'string'])
        ->and(app(PayloadCodec::class)->decode($step->fresh()->result))->toBe(1);
});

class MetadataBinaryDefaultStep extends Step
{
    public function handle(string $value = "\xB1"): int
    {
        return strlen($value);
    }
}

class MetadataDefault
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }
}

class MetadataService
{
    public string $secret = 'must not be recorded';
}

class MetadataSignatureStep extends Step
{
    public static int $calls = 0;

    public function __construct(private MetadataDefault $default = new MetadataDefault, private string $label = 'default label') {}

    public function handle(Context $context, MetadataService $service, string ...$values): array
    {
        self::$calls++;

        return [$this->label, $values];
    }
}
