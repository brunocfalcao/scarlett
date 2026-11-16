<?php

declare(strict_types=1);
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
