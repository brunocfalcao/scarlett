<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Scarlett\Contracts\PayloadMapper;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Inputs\Service;
use Scarlett\Tests\Fixtures\Amount;
use Scarlett\Tests\Fixtures\ControlledStep;
use Scarlett\Tests\Fixtures\PreparingStep;

beforeEach(function () {
    Queue::fake();
    $this->freezeSecond();
    ControlledStep::$actions = [];
    ControlledStep::$constructed = [];
    config(['scarlett.retry.attempts' => 2, 'scarlett.retry.backoff_seconds' => [7]]);
});

it('recovers temporary input failures after the configured wait without running the action early', function () {
    $calls = 0;
    app()->bind('test.input', function () use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('Temporary service outage.');
        }
        return 42;
    });
    $flow = app(Engine::class)->start(new Workflow([
        new Node('recover', ControlledStep::class, inputs: ['name' => 'recover', 'value' => new Service('test.input')]),
        controlled('next', stage: 1),
    ]));
    expect(statesOf($flow->id))->toBe(['next' => 'pending', 'recover' => 'queued']);
    runStep($flow->id, 'recover');
    $step = $flow->steps()->where('key', 'recover')->sole();
    expect((string) $step->state)->toBe('queued')->and($step->failures)->toBe(1)
        ->and($step->available_at->equalTo(now()->addSeconds(7)))->toBeTrue();
    expect(ControlledStep::$actions)->toBe([])->and(ControlledStep::$constructed)->toBe([]);
    runStep($flow->id, 'recover');
    expect($calls)->toBe(1);
    $this->travel(7)->seconds();
    runStep($flow->id, 'recover');
    expect(statesOf($flow->id))->toBe(['next' => 'queued', 'recover' => 'completed']);
    expect(app(PayloadCodec::class)->decode($step->fresh()->result))->toBe(42);
    runStep($flow->id, 'next');
    expect($flow->fresh()->status)->toBe('completed')->and(ControlledStep::$actions)->toBe(['recover' => 1, 'next' => 1]);
});

it('exhausts preparation retries and cancels only dependent work', function () {
    app()->bind('test.input', fn () => throw new RuntimeException('Still unavailable.'));
    $flow = app(Engine::class)->start(new Workflow([
        new Node('recover', ControlledStep::class, inputs: ['name' => 'recover', 'value' => new Service('test.input')]),
        controlled('next', stage: 1),
    ]));
    runStep($flow->id, 'recover');
    expect(statesOf($flow->id))->toBe(['next' => 'pending', 'recover' => 'queued']);
    $this->travel(7)->seconds();
    runStep($flow->id, 'recover');
    expect(statesOf($flow->id))->toBe(['next' => 'cancelled', 'recover' => 'failed']);
    expect($flow->steps()->where('key', 'recover')->sole()->failures)->toBe(2);
    expect(ControlledStep::$actions)->toBe([]);
});

it('fails invalid or deleted inputs without retrying', function (string $exception) {
    app()->bind('test.input', fn () => throw new $exception('Invalid input.'));
    $flow = app(Engine::class)->start(new Workflow([
        new Node('invalid', ControlledStep::class, inputs: ['name' => 'invalid', 'value' => new Service('test.input')]),
        controlled('healthy'),
    ]));
    expect(statesOf($flow->id))->toBe(['healthy' => 'queued', 'invalid' => 'queued']);
    runStep($flow->id, 'invalid');
    runStep($flow->id, 'healthy');
    expect(statesOf($flow->id))->toBe(['healthy' => 'completed', 'invalid' => 'failed']);
    expect($flow->steps()->where('key', 'invalid')->sole()->failures)->toBe(0);
    expect(ControlledStep::$actions)->toBe(['healthy' => 1]);
})->with([InvalidArgumentException::class, ModelNotFoundException::class, BindingResolutionException::class, TypeError::class]);

it('honors configured terminal input exception classes', function () {
    config(['scarlett.input_terminal_exceptions' => [RuntimeException::class]]);
    app()->bind('test.input', fn () => throw new RuntimeException('Consumer terminal failure.'));
    $flow = app(Engine::class)->start(new Workflow([
        new Node('invalid', ControlledStep::class, inputs: ['name' => 'invalid', 'value' => new Service('test.input')]),
    ]));
    runStep($flow->id, 'invalid');
    expect(statesOf($flow->id))->toBe(['invalid' => 'failed']);
    expect($flow->steps()->sole()->failures)->toBe(0);
});

it('uses the verification budget for preparation failures after an action was saved', function () {
    config(['scarlett.verification.attempts' => 2, 'scarlett.verification.backoff_seconds' => [3]]);
    $calls = 0;
    app()->bind('test.input', function () use (&$calls) {
        if (++$calls > 1) {
            throw new RuntimeException('Temporary service outage.');
        }
        return 42;
    });
    $flow = app(Engine::class)->start(new Workflow([
        new Node('verify', ControlledStep::class, inputs: ['name' => 'verify', 'mode' => 'verify', 'value' => new Service('test.input')]),
    ]));
    runStep($flow->id, 'verify');
    $step = $flow->steps()->sole();
    expect($step->action_completed)->toBeTrue()->and($step->verifications)->toBe(1);
    $this->travel(3)->seconds();
    runStep($flow->id, 'verify');
    expect((string) $step->fresh()->state)->toBe('failed')->and($step->fresh()->verifications)->toBe(2)
        ->and($step->fresh()->failures)->toBe(0)->and(ControlledStep::$actions)->toBe(['verify' => 1]);
});

it('retries temporary constructor failures without constructing successors early', function () {
    $calls = 0;
    app()->bind('test.prepare', function () use (&$calls) {
        return function () use (&$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('Temporary constructor failure.');
            }
        };
    });
    $flow = app(Engine::class)->start(new Workflow([
        new Node('prepare', PreparingStep::class, inputs: ['prepare' => new Service('test.prepare')]),
        controlled('next', stage: 1),
    ]));
    runStep($flow->id, 'prepare');
    expect(statesOf($flow->id))->toBe(['next' => 'pending', 'prepare' => 'queued'])
        ->and(ControlledStep::$constructed)->toBe([]);
    $this->travel(7)->seconds();
    runStep($flow->id, 'prepare');
    expect(statesOf($flow->id))->toBe(['next' => 'queued', 'prepare' => 'completed']);
    expect(app(PayloadCodec::class)->decode($flow->steps()->where('key', 'prepare')->sole()->result))->toBe(42);
});

it('retries mapped input restoration through the same budget', function () {
    $mapper = new class implements PayloadMapper {
        public int $loads = 0;

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
            if (++$this->loads === 1) {
                throw new RuntimeException('Temporary mapper failure.');
            }
            return new Amount($value);
        }
    };
    app()->instance($mapper::class, $mapper);
    config(['scarlett.payload_mappers' => ['amount' => $mapper::class]]);
    $flow = app(Engine::class)->start(new Workflow([
        new Node('mapped', ControlledStep::class, inputs: ['name' => 'mapped', 'value' => new Amount(42)]),
    ]));
    runStep($flow->id, 'mapped');
    expect(statesOf($flow->id))->toBe(['mapped' => 'queued'])->and(ControlledStep::$actions)->toBe([]);
    $this->travel(7)->seconds();
    runStep($flow->id, 'mapped');
    expect(statesOf($flow->id))->toBe(['mapped' => 'completed'])->and(ControlledStep::$actions)->toBe(['mapped' => 1]);
    expect(app(PayloadCodec::class)->decode($flow->steps()->sole()->result)->minorUnits)->toBe(42);
});
