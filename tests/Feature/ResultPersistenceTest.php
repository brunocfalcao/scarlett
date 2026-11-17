<?php

declare(strict_types=1);

/**
 * Separates a business return value from a successfully saved and verified result.
 *
 * Unsupported objects and injected write errors fail at persistence, after the action
 * has run. Verification must use the decoded stored type on the first call and retries.
 * A mapper that revokes authority during decode tests the recheck immediately before
 * verification, where an earlier ownership read is no longer sufficient.
 */

use Illuminate\Support\Facades\Queue;
use Scarlett\Contracts\PayloadMapper;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Models\StepRecord;
use Scarlett\Tests\Fixtures\Amount;
use Scarlett\Tests\Fixtures\AmountMapper;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\DateResultStep;
use Scarlett\Tests\Fixtures\UnstorableResultStep;
use Scarlett\Tests\Fixtures\VerifiedAmountStep;

beforeEach(function () {
    Queue::fake();
    ControlledStep::$actions = [];
});

it('fails an unstorable successful action without invoking it again', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('invalid', UnstorableResultStep::class, inputs: ['name' => 'invalid']),
        controlled('healthy'), controlled('throws', 'throw'),
    ]));
    expect(statesOf($flow->id))->toBe(['healthy' => 'queued', 'invalid' => 'queued', 'throws' => 'queued']);
    runStep($flow->id, 'invalid');
    runStep($flow->id, 'healthy');
    runStep($flow->id, 'throws');
    expect(statesOf($flow->id))->toBe(['healthy' => 'completed', 'invalid' => 'failed', 'throws' => 'queued']);
    expect(ControlledStep::$actions)->toBe(['invalid' => 1, 'healthy' => 1, 'throws' => 1]);
    $invalid = $flow->steps()->where('key', 'invalid')->sole();
    expect($invalid->failures)->toBe(0)->and($invalid->error)->toContain('Unsupported payload type');
});

it('fails a result write error without classifying it as an action failure', function () {
    $flow = app(Engine::class)->start(new Workflow([controlled('write'), controlled('untouched')]));
    StepRecord::saving(function (StepRecord $step): void {
        if ($step->key === 'write' && $step->isDirty('has_result')) {
            throw new RuntimeException('Result persistence unavailable.');
        }
    });
    runStep($flow->id, 'write');
    expect(statesOf($flow->id))->toBe(['untouched' => 'queued', 'write' => 'failed']);
    expect(ControlledStep::$actions)->toBe(['write' => 1]);
    expect($flow->steps()->where('key', 'write')->sole()->error)->toBe('Result persistence unavailable.');
});

it('verifies the same restored result type on first execution and retries', function () {
    $this->freezeSecond();
    DateResultStep::$verifiedTypes = [];
    $flow = app(Engine::class)->start(new Workflow([
        new Node('date', DateResultStep::class, inputs: ['name' => 'date']),
    ]));
    runStep($flow->id, 'date');
    expect(statesOf($flow->id))->toBe(['date' => 'queued']);
    $this->travel(5)->seconds();
    runStep($flow->id, 'date');
    expect(statesOf($flow->id))->toBe(['date' => 'completed']);
    expect(ControlledStep::$actions)->toBe(['date' => 1]);
    expect(DateResultStep::$verifiedTypes)->toBe([DateTimeImmutable::class, DateTimeImmutable::class]);
});

it('does not verify after ownership is revoked while restoring the saved result', function () {
    VerifiedAmountStep::$verifications = 0;
    config(['scarlett.payload_mappers' => ['amount' => AmountMapper::class]]);
    $flow = app(Engine::class)->start(new Workflow([new Node('owned', VerifiedAmountStep::class)]));
    app()->instance(AmountMapper::class, new class($flow->id) implements PayloadMapper
    {
        public function __construct(private string $workflow) {}

        public function supports(object $value): bool
        {
            return $value instanceof Amount;
        }

        public function encode(object $value): mixed
        {
            return $value->minorUnits;
        }

        public function decode(mixed $value): object
        {
            app(Engine::class)->cancelWorkflow($this->workflow);

            return new Amount($value);
        }
    });
    runStep($flow->id, 'owned');
    expect($flow->fresh()->status)->toBe('cancelled');
    expect(VerifiedAmountStep::$verifications)->toBe(0);
});
