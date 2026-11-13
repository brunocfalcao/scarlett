<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Scarlett\Definition\Node;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Failures\Coordinator;
use Scarlett\Failures\Status;
use Scarlett\Models\WorkflowRecord;
use Scarlett\Tests\Collision\Workers;
use Scarlett\Tests\Fixtures\FailureAwareStep;

beforeEach(function () {
    Schema::create('consumer_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->integer('writes')->default(0);
    });
});

it('serializes competing replacements for the same slot without consumer retries', function () {
    $slot = new SubjectSlot('consumer', (string) Str::uuid(), 'current');
    $engine = app(Engine::class);
    $old = $engine->replace($slot, fn () => new Workflow([controlled('old')]));
    $other = $engine->replace(new SubjectSlot('consumer', $slot->subjectId, 'mail'), fn () => new Workflow([controlled('mail')]));
    $operation = ['operation' => 'replace', 'subject' => $slot->subjectId];
    $results = Workers::race($slot->id(), [$operation, $operation], 'scarlett_subject_slots');
    $ids = array_column($results, 'result');
    expect($ids[0])->not->toBe($ids[1]);
    $current = $engine->current($slot);
    expect($current)->toBeIn($ids)->and($old->fresh()->status)->toBe('cancelled')->and($other->fresh()->status)->toBe('running');
    $superseded = array_values(array_diff($ids, [$current]))[0];
    expect(WorkflowRecord::findOrFail($superseded)->status)->toBe('cancelled');
    expect(WorkflowRecord::findOrFail($current)->status)->toBe('running');
});

it('serializes first admission of a subject slot', function () {
    $slot = new SubjectSlot('consumer', (string) Str::uuid(), 'current');
    $operation = ['operation' => 'replace', 'subject' => $slot->subjectId];
    $results = Workers::race('', [$operation, $operation], 'scarlett_control');
    $ids = array_column($results, 'result');
    expect($ids[0])->not->toBe($ids[1])->and(app(Engine::class)->current($slot))->toBeIn($ids);
    expect(WorkflowRecord::whereKey($ids)->where('status', 'running')->count())->toBe(1);
});

it('keeps workflow-before-consumer lock ordering when replacing against a guarded writer', function (bool $veto) {
    $record = (string) Str::uuid();
    DB::table('consumer_records')->insert(['id' => $record, 'writes' => 0]);
    $slot = new SubjectSlot('consumer', $record, 'current');
    $engine = app(Engine::class);
    $old = $engine->replace($slot, fn () => new Workflow([controlled('old')]));
    $step = $old->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    $results = Workers::race($old->id, [
        ['operation' => 'write', 'record' => $record, 'step' => $step->id, 'attempt' => $step->attempt],
        ['operation' => 'replace', 'record' => $record, 'subject' => $record, 'veto' => $veto],
    ]);
    expect(DB::table('consumer_records')->where('id', $record)->value('writes'))->toBe($veto ? 1 : 1 + (int) $results[0]['result']);
    expect($engine->isCurrent($step->id, $step->attempt))->toBe($veto);
    expect($engine->current($slot))->toBe($veto ? $old->id : $results[1]['result']);
})->with([true, false]);

it('claims a failure hook only once under duplicate worker delivery and fences an expired handler', function () {
    $flow = app(Engine::class)->start(new Workflow([
        new Node('action', FailureAwareStep::class, inputs: ['name' => 'action', 'mode' => 'fail']),
    ]));
    runStep($flow->id, 'action');
    $failure = $flow->failures()->sole();
    $operation = ['operation' => 'failure-claim', 'failure' => $failure->id, 'attempt' => $failure->attempt];
    $claims = Workers::race($flow->id, [$operation, $operation]);
    expect(array_sum(array_column($claims, 'result')))->toBe(1)->and($failure->fresh()->status)->toBe(Status::Running);
    $record = (string) Str::uuid();
    DB::table('consumer_records')->insert(['id' => $record, 'writes' => 0]);
    $failure->update(['lease_until' => now()->subSecond()]);
    $base = ['failure' => $failure->id, 'attempt' => $failure->attempt, 'record' => $record];
    $results = Workers::race($flow->id, [['operation' => 'failure-recover'] + $base, ['operation' => 'failure-write'] + $base]);
    expect(array_column($results, 'result'))->toBe([true, false])
        ->and(DB::table('consumer_records')->where('id', $record)->value('writes'))->toBe(0)
        ->and($failure->fresh()->status)->toBe(Status::Queued)
        ->and($failure->fresh()->failures)->toBe(1);
});
