<?php

declare(strict_types=1);
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Tests\Fixtures\EchoStep;
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
