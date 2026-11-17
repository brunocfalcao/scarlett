<?php

declare(strict_types=1);

/**
 * Checks observable work performed by the engine, not elapsed-time guesses.
 *
 * Retrieval hooks, query logs, collection counters, and an SQLite query plan reveal
 * extra row loads or repeated full-graph scans. Correct final workflow status alone
 * would miss those costs. Configuration changes are also checked so registry caching
 * does not trade correctness for fewer validation calls.
 */

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Execution\Delivery;
use Scarlett\Failures\Coordinator;
use Scarlett\Jobs\ExecuteStep;
use Scarlett\Models\StepRecord;
use Scarlett\States\StateRegistry;
use Scarlett\Tests\Fixtures\Approved;
use Scarlett\Tests\Fixtures\MeasuredState;

it('does not reload sibling steps for a heartbeat or guarded consumer write', function () {
    Queue::fake();
    $engine = app(Engine::class);
    $flow = $engine->start(new Workflow(array_map(fn ($index) => controlled('step-'.$index), range(1, 40))));
    $step = $flow->steps()->where('key', 'step-1')->sole();
    $engine->claim($step->id, $step->attempt);
    $loaded = [];
    StepRecord::retrieved(function (StepRecord $record) use (&$loaded): void {
        $loaded[] = $record->id;
    });
    expect($engine->heartbeat($step->id, $step->attempt))->toBeTrue();
    expect($loaded)->toBe([$step->id]);
    $loaded = [];
    $writes = 0;
    expect($engine->whileCurrent($step->id, $step->attempt, function () use (&$writes): void {
        $writes++;
    }))->toBeTrue();
    expect($loaded)->toBe([$step->id])->and($writes)->toBe(1);
    expect($flow->fresh()->status)->toBe('running');
});

it('walks failure descendants through the indexed parent map', function () {
    $childrenByParent = new class(['root' => collect([(object) ['key' => 'child']]), 'child' => collect([(object) ['key' => 'leaf']])]) extends Collection
    {
        public int $getCalls = 0;

        public int $filterCalls = 0;

        public function get($key, $default = null)
        {
            $this->getCalls++;

            return parent::get($key, $default);
        }

        public function filter(?callable $callback = null)
        {
            $this->filterCalls++;

            return parent::filter($callback);
        }
    };
    $method = (new ReflectionClass(Coordinator::class))->getMethod('descendants');

    $descendants = $method->invoke(app(Coordinator::class), $childrenByParent, 'root');

    expect($descendants->pluck('key')->all())->toBe(['child', 'leaf'])
        ->and($childrenByParent->filterCalls)->toBe(0)
        ->and($childrenByParent->getCalls)->toBe(3);
});

it('restricts recovery candidates to indexed active states', function () {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow([controlled('done')]));
    runStep($flow->id, 'done');
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(app(Engine::class)->stalled())->toBeFalse();
    $query = collect(DB::getQueryLog())->first(fn ($query) => str_contains($query['query'], 'scarlett_steps'));
    DB::disableQueryLog();
    if (DB::connection()->getDriverName() === 'sqlite') {
        $plan = DB::select('EXPLAIN QUERY PLAN '.$query['query'], $query['bindings']);
        expect(implode(' ', array_column($plan, 'detail')))->toContain('scarlett_steps_state_index');
    }
    expect(collect(Schema::getIndexes('scarlett_steps'))->contains(fn (array $index): bool => $index['columns'] === ['state']))->toBeTrue();
    expect(str_replace('`', '"', $query['query']))->toContain('"state" in')
        ->and($query['bindings'])->toContain('queued', 'running')->not->toContain('completed');
    expect($flow->fresh()->status)->toBe('completed');
});

it('reuses registry validation across hydration and refreshes changed configuration', function () {
    Queue::fake();
    config(['scarlett.states' => [MeasuredState::class]]);
    $flow = app(Engine::class)->start(new Workflow([controlled('cached')]));
    $step = $flow->steps()->sole();
    $reads = MeasuredState::$phaseReads;
    foreach (range(1, 10) as $index) {
        expect((string) $step->fresh()->state)->toBe('queued');
    }
    expect(MeasuredState::$phaseReads)->toBe($reads);
    config(['scarlett.states' => [Approved::class]]);
    expect(app(StateRegistry::class)->classes())
        ->toContain(Approved::class)->not->toContain(MeasuredState::class);
});

it('loads publication steps in one batch and excludes obsolete deliveries', function () {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow(array_map(fn ($index) => controlled('delivery-'.$index), range(1, 5))));
    $steps = $flow->steps()->get();
    DB::table('scarlett_deliveries')->whereIn('step_id', $steps->modelKeys())->update(['sent_at' => null]);
    $obsolete = (string) Str::uuid();
    DB::table('scarlett_deliveries')->insert([
        'id' => (string) Str::uuid(), 'step_id' => $steps->first()->id, 'attempt' => $obsolete,
        'available_at' => now(), 'sent_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(Delivery::class)->flush($flow->id);
    $lookups = collect(DB::getQueryLog())
        ->filter(fn ($query) => str_starts_with(str_replace('`', '"', $query['query']), 'select * from "scarlett_steps"'));
    DB::disableQueryLog();
    expect($lookups)->toHaveCount(1);
    Queue::assertNotPushed(ExecuteStep::class, fn ($job) => $job->attempt === $obsolete);
    Queue::assertPushed(ExecuteStep::class, 10);
});
