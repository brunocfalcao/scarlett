<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Models\StepRecord;

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
    expect($engine->whileCurrent($step->id, $step->attempt, function () use (&$writes): void { $writes++; }))->toBeTrue();
    expect($loaded)->toBe([$step->id])->and($writes)->toBe(1);
    expect($flow->fresh()->status)->toBe('running');
});

it('uses the state index to exclude retained history from recovery candidates', function () {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow([controlled('done')]));
    runStep($flow->id, 'done');
    \Illuminate\Support\Facades\DB::enableQueryLog();
    \Illuminate\Support\Facades\DB::flushQueryLog();
    expect(app(Engine::class)->stalled())->toBeFalse();
    $query = collect(\Illuminate\Support\Facades\DB::getQueryLog())->first(fn ($query) => str_contains($query['query'], 'scarlett_steps'));
    \Illuminate\Support\Facades\DB::disableQueryLog();
    $plan = \Illuminate\Support\Facades\DB::select('EXPLAIN QUERY PLAN '.$query['query'], $query['bindings']);
    expect(implode(' ', array_column($plan, 'detail')))->toContain('scarlett_steps_state_index');
    expect($flow->fresh()->status)->toBe('completed');
});

it('reuses registry validation across hydration and refreshes changed configuration', function () {
    Queue::fake();
    config(['scarlett.states' => [\Scarlett\Tests\Fixtures\MeasuredState::class]]);
    $flow = app(Engine::class)->start(new Workflow([controlled('cached')]));
    $step = $flow->steps()->sole();
    $reads = \Scarlett\Tests\Fixtures\MeasuredState::$phaseReads;
    foreach (range(1, 10) as $index) {
        expect((string) $step->fresh()->state)->toBe('queued');
    }
    expect(\Scarlett\Tests\Fixtures\MeasuredState::$phaseReads)->toBe($reads);
    config(['scarlett.states' => [\Scarlett\Tests\Fixtures\Approved::class]]);
    expect(app(\Scarlett\States\StateRegistry::class)->classes())
        ->toContain(\Scarlett\Tests\Fixtures\Approved::class)->not->toContain(\Scarlett\Tests\Fixtures\MeasuredState::class);
});

it('loads publication steps in one batch and excludes obsolete deliveries', function () {
    Queue::fake();
    $flow = app(Engine::class)->start(new Workflow(array_map(fn ($index) => controlled('delivery-'.$index), range(1, 5))));
    $steps = $flow->steps()->get();
    \Illuminate\Support\Facades\DB::table('scarlett_deliveries')->whereIn('step_id', $steps->modelKeys())->update(['sent_at' => null]);
    $obsolete = (string) \Illuminate\Support\Str::uuid();
    \Illuminate\Support\Facades\DB::table('scarlett_deliveries')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(), 'step_id' => $steps->first()->id, 'attempt' => $obsolete,
        'available_at' => now(), 'sent_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::enableQueryLog();
    \Illuminate\Support\Facades\DB::flushQueryLog();
    app(\Scarlett\Execution\Delivery::class)->flush($flow->id);
    $lookups = collect(\Illuminate\Support\Facades\DB::getQueryLog())
        ->filter(fn ($query) => str_starts_with($query['query'], 'select * from "scarlett_steps"'));
    \Illuminate\Support\Facades\DB::disableQueryLog();
    expect($lookups)->toHaveCount(1);
    Queue::assertNotPushed(\Scarlett\Jobs\ExecuteStep::class, fn ($job) => $job->attempt === $obsolete);
    Queue::assertPushed(\Scarlett\Jobs\ExecuteStep::class, 10);
});
