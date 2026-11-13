<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Scarlett\Definition\Node;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\History;
use Scarlett\Inputs\ModelReference;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Tests\Fixtures\EchoStep;
use Scarlett\Tests\Fixtures\SourceRecord;

it('restores an explicit model reference from current data while snapshots remain unchanged', function () {
    Schema::create('source_records', fn (Blueprint $table) => [$table->id(), $table->string('name')]);
    config(['scarlett.models' => ['source' => SourceRecord::class]]);
    $source = SourceRecord::create(['name' => 'original']);
    $codec = app(PayloadCodec::class);
    $reference = $codec->encode(new ModelReference('source', $source->id));
    $snapshot = $codec->encode($source->getAttributes());
    $source->update(['name' => 'changed']);
    expect($codec->decode($reference)->name)->toBe('changed');
    expect($codec->decode($snapshot)['name'])->toBe('original');
    $source->delete();
    expect(fn () => $codec->decode($reference))->toThrow(ModelNotFoundException::class);
});
it('purges only archived settled history and preserves active and newer workflows', function () {
    $this->freezeSecond();
    $engine = app(Engine::class);
    $history = app(History::class);
    $settled = $engine->start(new Workflow([new Node('done', EchoStep::class)]));
    $untouched = $engine->start(new Workflow([new Node('new', EchoStep::class)]));
    Queue::fake();
    $active = $engine->start(new Workflow([new Node('active', EchoStep::class)]));
    expect($history->archive($active->id))->toBeFalse();
    expect($history->archive($settled->id))->toBeTrue();
    $this->travel(1)->seconds();
    expect($history->purge(now()))->toBe(1);
    expect($settled->fresh())->toBeNull();
    expect($untouched->fresh()->status)->toBe('completed');
    expect($active->fresh()->status)->toBe('running');
});
it('dispatches eligible priority order and keeps queue routing per step', function () {
    $jobs = [];
    Bus::shouldReceive('dispatch')->andReturnUsing(function ($job) use (&$jobs) {
        $jobs[] = $job;
    });
    $flow = app(Engine::class)->start(new Workflow([
        new Node('low', EchoStep::class, priority: 1, queue: 'slow'),
        new Node('high', EchoStep::class, priority: 10, queue: 'urgent'),
        new Node('later', EchoStep::class, stage: 1, priority: 100),
    ]));
    expect(array_column($jobs, 'queue'))->toBe(['urgent', 'slow']);
    expect(statesOf($flow->id))->toBe(['high' => 'queued', 'later' => 'pending', 'low' => 'queued']);
});
