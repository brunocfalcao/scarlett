<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\History;
use Scarlett\Inputs\FromResult;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Inputs\Service;
use Scarlett\States\Running;
use Scarlett\Tests\Fixtures\Amount;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\Approved;
use Scarlett\Tests\Fixtures\ApproveStep;
use Scarlett\Tests\Fixtures\EchoStep;
use Scarlett\Tests\Fixtures\TypedStep;

beforeEach(function () {
    config(['scarlett.payload_mappers' => ['amount-v1' => AmountMapper::class], 'scarlett.states' => [Approved::class], 'scarlett.transitions' => [[Running::class, Approved::class]]]);
    TypedStep::$received = [];
});
it('round trips false zero null empty nested values dates collections and mapped DTOs', function () {
    $codec = app(PayloadCodec::class);
    $values = [false, 0, null, [], ['x' => collect([new Amount(123), new DateTimeImmutable('2026-01-01T01:02:03+02:00')])]];
    $restored = $codec->decode(json_decode(json_encode($codec->encode($values), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    expect(array_slice($restored, 0, 4))->toBe([false, 0, null, []]);
    expect($restored[4]['x'][0]->minorUnits)->toBe(123)->and($restored[4]['x'][1]->format(DATE_ATOM))->toBe('2026-01-01T01:02:03+02:00');
});
it('applies custom success semantics to progression and retention', function () {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow([
        new Node('approved', ApproveStep::class),
        new Node('typed', TypedStep::class, stage: 1, inputs: ['amount' => new FromResult('approved'), 'settings' => new Service(Repository::class), 'multiplier' => 2]),
    ]));
    expect(statesOf($flow->id))->toBe(['approved' => 'queued', 'typed' => 'pending']);
    expect(app(History::class)->archive($flow->id))->toBeFalse();
    runStep($flow->id, 'approved');
    expect(statesOf($flow->id))->toBe(['approved' => 'approved', 'typed' => 'queued']);
    runStep($flow->id, 'typed');
    expect(TypedStep::$received)->toBe([123]);
    expect(app(PayloadCodec::class)->decode($flow->steps()->where('key', 'typed')->sole()->result)->minorUnits)->toBe(246);
    expect($flow->fresh()->status)->toBe('completed');
    expect(app(History::class)->archive($flow->id))->toBeTrue();
});
it('rejects unknown payload versions and arbitrary objects', function () {
    expect(fn () => app(PayloadCodec::class)->decode(['version' => 99, 'value' => []]))->toThrow(InvalidArgumentException::class, 'version');
    expect(fn () => app(PayloadCodec::class)->encode(new stdClass))->toThrow(InvalidArgumentException::class, 'Unsupported');
});
it('fails an absent predecessor result rather than inventing a value', function () {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow([controlled('a', 'skip'), new Node('b', EchoStep::class, stage: 1, inputs: ['value' => new FromResult('a')])]));
    runStep($flow->id, 'a');
    runStep($flow->id, 'b');
    expect(statesOf($flow->id))->toBe(['a' => 'skipped', 'b' => 'failed']);
    expect($flow->steps()->where('key', 'b')->sole()->error)->toBe('Step a did not produce a result.');
});
it('rejects coercion of persisted inputs into incompatible scalar signatures', function () {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow([new Node('typed', TypedStep::class, inputs: ['amount' => new Amount(2), 'settings' => new Service(Repository::class), 'multiplier' => '3'])]));
    runStep($flow->id, 'typed');
    expect(TypedStep::$received)->toBe([]);
    expect((string) $flow->steps()->sole()->state)->not->toBe('completed');
});
it('preserves floating point types through the persisted JSON representation', function () {
    $codec = app(PayloadCodec::class);
    $stored = json_decode(json_encode($codec->encode([1.0, 0.0, 1]), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect($codec->decode($stored))->toBe([1.0, 0.0, 1]);
});
