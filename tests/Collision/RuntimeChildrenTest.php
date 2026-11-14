<?php

declare(strict_types=1);

use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Tests\Collision\Workers;

it('admits one runtime plan when duplicate current attempts race', function (int $round) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent')], metadata: ['round' => $round]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    $operation = ['operation' => 'children', 'step' => $parent->id, 'attempt' => $parent->attempt];
    expect((string) $parent->fresh()->state)->toBe('running');
    $results = Workers::race($flow->id, [$operation, $operation]);
    expect(array_column($results, 'result'))->toEqualCanonicalizing([true, false]);
    expect($flow->steps()->count())->toBe(2);
    expect((string) $parent->fresh()->state)->toBe('waitingforchildren');
    runStep($flow->id, $parent->id.'/child');
    runStep($flow->id, 'parent');
    expect($flow->fresh()->status)->toBe('completed');
})->with([1, 2, 3]);

it('fences runtime admission against workflow revocation', function (int $round) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent')], metadata: ['round' => $round]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    $results = Workers::race($flow->id, [
        ['operation' => 'children', 'step' => $parent->id, 'attempt' => $parent->attempt],
        ['operation' => 'revoke', 'workflow' => $flow->id],
    ]);
    expect($results[1]['result'])->toBeTrue();
    expect($flow->steps()->count())->toBe($results[0]['result'] ? 2 : 1);
    expect($flow->steps()->get()->map(fn ($s) => (string) $s->state)->unique()->values()->all())->toBe(['cancelled']);
    expect($flow->fresh()->status)->toBe('cancelled');
})->with([1, 2, 3]);

it('rejects an expired plan while recovery admits the replacement attempt', function (int $round) {
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow([controlled('parent')], metadata: ['round' => $round]));
    $parent = $flow->steps()->sole();
    $engine->claim($parent->id, $parent->attempt);
    $parent->update(['lease_until' => now()->subSecond()]);
    $base = ['step' => $parent->id, 'attempt' => $parent->attempt];
    $results = Workers::race($flow->id, [['operation' => 'children'] + $base, ['operation' => 'recover'] + $base]);
    expect(array_column($results, 'result'))->toBe([false, true]);
    expect($flow->steps()->count())->toBe(1);
    expect((string) $parent->fresh()->state)->toBe('queued');
    expect($parent->fresh()->attempt)->not->toBe($parent->attempt);
})->with([1, 2, 3]);
