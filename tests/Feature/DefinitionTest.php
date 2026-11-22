<?php

declare(strict_types=1);

/**
 * Checks declarations before business work is constructed or persisted.
 *
 * Use small Node graphs for duplicate names, cycles, missing bindings, UTF-8 storage
 * limits, and PHP parameter compatibility. Constructor logs prove validation does
 * not instantiate future work. Shared service interfaces demonstrate that a container
 * alias must be judged by its resolved value at execution, not by its lookup name.
 */
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Inputs\Service;
use Scarlett\Step;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\EchoStep;
use Scarlett\Tests\Fixtures\LiteralTypesStep;
use Scarlett\Tests\Fixtures\TypedStep;

it('rejects duplicate identities before any work can be registered', function () {
    (new Workflow([new Node('same', EchoStep::class), new Node('same', EchoStep::class)]))->validate();
})->throws(InvalidArgumentException::class, 'Duplicate');
it('rejects parent cycles without instantiating business steps', function () {
    (new Workflow([new Node('a', EchoStep::class, parent: 'b'), new Node('b', EchoStep::class, parent: 'a')]))->validate();
})->throws(InvalidArgumentException::class, 'Cycle');
it('accepts sequence and parallel definitions', function () {
    $flow = new Workflow([new Node('a', EchoStep::class), new Node('b', EchoStep::class), new Node('c', EchoStep::class, stage: 1)]);
    $flow->validate();
    expect(array_column($flow->nodes, 'key'))->toBe(['a', 'b', 'c']);
});
it('rejects a missing required binding before persisting the workflow', function () {
    $definition = new Workflow([new Node('typed', TypedStep::class)]);
    expect(fn () => app(Engine::class)->start($definition))->toThrow(InvalidArgumentException::class, 'Missing input');
});
it('rejects misspelled bindings instead of silently ignoring them', function () {
    (new Workflow([new Node('echo', EchoStep::class, inputs: ['valuue' => 1])]))->validate();
})->throws(InvalidArgumentException::class, 'Unknown input');

it('validates the key storage boundary before admitting work', function () {
    (new Workflow([new Node(str_repeat('é', 255), EchoStep::class)]))->validate();
    expect(fn () => (new Workflow([new Node(str_repeat('é', 256), EchoStep::class)]))->validate())
        ->toThrow(InvalidArgumentException::class, 'identity');
});

it('rejects invalid UTF-8 and oversized bounded workflow strings before admission', function () {
    expect(fn () => (new Workflow([new Node("\xFF", EchoStep::class)]))->validate())
        ->toThrow(InvalidArgumentException::class, 'UTF-8');
    expect(fn () => (new Workflow([new Node('valid', EchoStep::class, queue: str_repeat('q', 256))]))->validate())
        ->toThrow(InvalidArgumentException::class, 'queue');
    expect(fn () => (new Workflow([new Node('valid', EchoStep::class, connection: str_repeat('c', 256))]))->validate())
        ->toThrow(InvalidArgumentException::class, 'connection');
    expect(fn () => (new Workflow([new Node('valid', EchoStep::class)], subjectType: str_repeat('t', 256), subjectId: '1'))->validate())
        ->toThrow(InvalidArgumentException::class, 'subject type');
    expect(fn () => (new Workflow([new Node('valid', EchoStep::class)], title: "\xFF"))->validate())
        ->toThrow(InvalidArgumentException::class, 'UTF-8');
});

it('rejects empty routing names and unencodable metadata before admission', function (Workflow $definition, string $message) {
    expect(fn () => $definition->validate())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty queue' => [new Workflow([new Node('valid', EchoStep::class, queue: '')]), 'queue must not be empty'],
    'empty connection' => [new Workflow([new Node('valid', EchoStep::class, connection: '')]), 'connection must not be empty'],
    'invalid UTF-8 metadata' => [new Workflow([new Node('valid', EchoStep::class)], metadata: ['bad' => "\xFF"]), 'cannot be encoded as JSON'],
    'non-finite metadata number' => [new Workflow([new Node('valid', EchoStep::class)], metadata: ['bad' => NAN]), 'cannot be encoded as JSON'],
]);

it('rejects self-containing metadata as an invalid definition', function () {
    $metadata = [];
    $metadata[] = &$metadata;

    expect(fn () => (new Workflow([new Node('valid', EchoStep::class)], metadata: $metadata))->validate())
        ->toThrow(InvalidArgumentException::class, 'cannot be encoded as JSON');
});

it('accepts a one-character queue and connection and JSON-safe metadata', function () {
    (new Workflow([new Node('valid', EchoStep::class, queue: 'q', connection: 'c')], metadata: ['note' => 'café', 'count' => 2]))->validate();

    expect(true)->toBeTrue();
});

it('reports the first invalid part of a definition in its documented order', function (Workflow $definition, string $message) {
    expect(fn () => $definition->validate())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'subject pair before invalid node' => [new Workflow([new Node('valid', stdClass::class)], subjectType: 'order'), 'requires both its type and its identifier'],
    'subject text before metadata' => [new Workflow([new Node('valid', EchoStep::class)], subjectType: "\xFF", subjectId: '1', metadata: ['bad' => NAN]), 'subject type must be valid UTF-8'],
    'metadata before node identity' => [new Workflow([new Node('', EchoStep::class)], metadata: ['bad' => NAN]), 'cannot be encoded as JSON'],
    'node before ancestry' => [new Workflow([new Node('a', EchoStep::class, parent: 'missing'), new Node('a', EchoStep::class)]), 'Duplicate step identity'],
]);

it('rejects incompatible literal constructor types before constructing any step', function () {
    ControlledStep::$constructed = [];
    expect(fn () => app(Engine::class)->start(new Workflow([
        new Node('invalid', ControlledStep::class, inputs: ['name' => 123]),
    ])))->toThrow(InvalidArgumentException::class, 'name');
    expect(ControlledStep::$constructed)->toBe([]);
});

it('preserves union nullable boolean and variadic literal types through execution', function (int|float $number, ?string $label, bool $enabled, array $increments) {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('types', LiteralTypesStep::class, inputs: compact('number', 'label', 'enabled', 'increments')),
    ]));
    expect($flow->fresh()->status)->toBe('completed');
    expect(app(PayloadCodec::class)->decode($flow->steps()->sole()->result))->toBe([$number, $label, $enabled, $increments]);
})->with([[0, '', false, []], [1.0, null, true, [0, 2]]]);

it('rejects incompatible variadic literals before admission', function () {
    expect(fn () => app(Engine::class)->start(new Workflow([
        new Node('types', LiteralTypesStep::class, inputs: ['number' => 1, 'increments' => ['2']]),
    ])))->toThrow(InvalidArgumentException::class, 'increments');
});

it('resolves shared constructor and handler service inputs through late-bound container aliases', function () {
    app()->instance('shared-input-test-service', new CompatibleSharedService);
    $flow = app(Engine::class)->start(new Workflow([new Node('shared', CompatibleInputNameStep::class, inputs: [
        'target' => new Service('shared-input-test-service'),
    ])]));
    expect($flow->fresh()->status)->toBe('completed')
        ->and(app(PayloadCodec::class)->decode($flow->steps()->sole()->result))->toBe('shared');
});

interface ConstructorServiceContract {}
interface HandlerServiceContract {}
class CompatibleSharedService implements ConstructorServiceContract, HandlerServiceContract {}
class CompatibleInputNameStep extends Step
{
    public function __construct(ConstructorServiceContract $target) {}

    public function handle(HandlerServiceContract $target): string
    {
        return 'shared';
    }
}

it('validates long ancestry chains and still rejects a cycle reached after acyclic siblings were verified', function () {
    $chain = [new Node('n0', EchoStep::class)];
    for ($index = 1; $index < 400; $index++) {
        $chain[] = new Node("n$index", EchoStep::class, parent: 'n'.($index - 1));
    }
    $chain[] = new Node('sibling', EchoStep::class, parent: 'n399');

    expect(fn () => (new Workflow($chain))->validate())->not->toThrow(InvalidArgumentException::class);

    $cycle = [
        new Node('root', EchoStep::class),
        new Node('ok', EchoStep::class, parent: 'root'),
        new Node('loop-a', EchoStep::class, parent: 'loop-b'),
        new Node('loop-b', EchoStep::class, parent: 'loop-a'),
        new Node('enters', EchoStep::class, parent: 'loop-a'),
    ];

    expect(fn () => (new Workflow($cycle))->validate())->toThrow(InvalidArgumentException::class, 'Cycle in workflow ancestry.');
});

it('stores the earlier-stage sibling prerequisites of fanouts, child groups and rescue groups', function () {
    $nodes = [];
    foreach (range(0, 5) as $index) {
        $nodes[] = new Node("fan$index", EchoStep::class);
    }
    $nodes[] = new Node('join', EchoStep::class, stage: 1);
    $nodes[] = new Node('after', EchoStep::class, stage: 2);
    $nodes[] = new Node('child-a', EchoStep::class, parent: 'fan0');
    $nodes[] = new Node('child-b', EchoStep::class, stage: 1, parent: 'fan0');
    $nodes[] = new Node('other-child', EchoStep::class, stage: 1, parent: 'fan1');
    $nodes[] = new Node('rescue', EchoStep::class, rescues: 'join');
    $nodes[] = new Node('rescue-late', EchoStep::class, stage: 1, rescues: 'join');

    $workflow = app(Engine::class)->start(new Workflow($nodes));
    $stored = $workflow->steps()->get()->mapWithKeys(fn ($step): array => [$step->key => $step->dependencies])->all();
    ksort($stored);
    $fan = ['fan0', 'fan1', 'fan2', 'fan3', 'fan4', 'fan5'];
    $expected = array_fill_keys($fan, []) + [
        'join' => $fan,
        'after' => [...$fan, 'join'],
        'child-a' => [],
        'child-b' => ['child-a'],
        'other-child' => [],
        'rescue' => [],
        'rescue-late' => ['rescue'],
    ];
    ksort($expected);

    expect($stored)->toBe($expected);
});
