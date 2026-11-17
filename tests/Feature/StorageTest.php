<?php

declare(strict_types=1);

/**
 * Checks retained payload meaning, queue routing, and history removal boundaries.
 *
 * A live ModelReference follows current row values while a plain snapshot retains
 * old values. Date restoration preserves named timezones and old payload support.
 * Retention compares instants rather than timezone labels and preserves active or
 * newer work; routing checks inspect the actual jobs selected for publication.
 */

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

it('purges by the cutoff instant regardless of its timezone', function (string $timezone) {
    $this->travelTo(Carbon::parse('2026-01-01 03:00:00', 'UTC'));
    $engine = app(Engine::class);
    $history = app(History::class);
    $old = $engine->start(new Workflow([new Node('old', EchoStep::class)]));
    $history->archive($old->id);
    $this->travel(3)->hours();
    $new = $engine->start(new Workflow([new Node('new', EchoStep::class)]));
    $history->archive($new->id);
    $cutoff = Carbon::parse('2026-01-01 05:00:00', 'UTC')->setTimezone($timezone);
    expect($old->fresh()->status)->toBe('completed');
    expect($history->purge($cutoff))->toBe(1)->and($history->purge($cutoff))->toBe(0);
    expect($old->fresh())->toBeNull()->and($new->fresh()->status)->toBe('completed');
})->with(['America/New_York', 'Asia/Tokyo', 'UTC']);

it('keeps unknown archive exceptional and unknown deletion idempotent', function () {
    $id = (string) Str::uuid();
    expect(fn () => app(History::class)->archive($id))->toThrow(ModelNotFoundException::class);
    expect(app(History::class)->delete($id))->toBeFalse();
});

it('preserves named date timezones and daylight-saving arithmetic while reading old payloads', function () {
    $codec = app(PayloadCodec::class);
    $date = new DateTime('2026-10-01 12:00:00.123456', new DateTimeZone('Europe/Lisbon'));
    $restored = $codec->decode(json_decode(json_encode($codec->encode($date), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    expect($restored)->toBeInstanceOf(DateTimeImmutable::class);
    expect($restored->getTimezone()->getName())->toBe('Europe/Lisbon');
    expect($restored->modify('+1 month')->format('Y-m-d H:i:s.u P'))->toBe('2026-11-01 12:00:00.123456 +00:00');
    $legacy = ['version' => 1, 'value' => ['type' => 'date', 'data' => '2026-10-01T12:00:00.123456+01:00']];
    expect($codec->decode($legacy)->getTimezone()->getName())->toBe('+01:00');
});
