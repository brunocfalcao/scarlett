<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Scarlett\Definition\Subject;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\Models\WorkflowRecord;
use Scarlett\Tests\Collision\Environment;
use Scarlett\Tests\Collision\Workers;

beforeEach(function () {
    Schema::create('consumer_records', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->integer('writes')->default(0);
    });
});

it('serializes deletion and a guarded consumer write with optional rollback veto', function (bool $veto) {
    $engine = app(Engine::class);
    $subject = (string) Str::uuid();
    DB::table('consumer_records')->insert(['id' => $subject, 'writes' => 0]);
    $flow = $engine->replace(new SubjectSlot('consumer', $subject, 'current'), fn () => new Workflow([controlled('active')]));
    $step = $flow->steps()->sole();
    $engine->claim($step->id, $step->attempt);
    $results = Workers::race($flow->id, [
        ['operation' => 'write', 'step' => $step->id, 'attempt' => $step->attempt, 'record' => $subject],
        ['operation' => 'delete-subject', 'subject' => $subject, 'record' => $subject, 'veto' => $veto],
    ]);
    expect($results[1]['result'])->toBe(! $veto)
        ->and(WorkflowRecord::whereKey($flow->id)->exists())->toBe($veto)
        ->and($engine->isCurrent($step->id, $step->attempt))->toBe($veto)
        ->and(DB::table('consumer_records')->where('id', $subject)->value('writes'))->toBe($veto ? 1 : 1 + (int) $results[0]['result']);
})->with([false, true]);

it('stabilizes a related subjects running-state veto against a simultaneous worker claim', function () {
    $engine = app(Engine::class);
    $subject = (string) Str::uuid();
    $guardSubject = (string) Str::uuid();
    $flow = $engine->start(new Workflow([controlled('target')], 'consumer', $subject));
    $guard = $engine->start(new Workflow([controlled('send')], 'consumer', $guardSubject));
    $step = $guard->steps()->sole();
    $results = Workers::race($guard->id, [
        ['operation' => 'claim', 'step' => $step->id, 'attempt' => $step->attempt],
        ['operation' => 'delete-subject', 'subject' => $subject, 'guards' => [$guardSubject], 'check_running' => true],
    ]);
    expect($results[0]['result'])->toBeTrue()
        ->and($guard->fresh()->status)->toBe('running')
        ->and($engine->isCurrent($step->id, $step->attempt))->toBeTrue();
    expect(WorkflowRecord::whereKey($flow->id)->exists())->toBe(! $results[1]['result']);
});

it('serializes subject deletion with independent admission and consumer existence checks', function () {
    $subject = (string) Str::uuid();
    DB::table('consumer_records')->insert(['id' => $subject, 'writes' => 0]);
    $base = ['subject' => $subject, 'record' => $subject];
    $results = Workers::race('', [
        ['operation' => 'subject-admit'] + $base,
        ['operation' => 'delete-subject', 'delete_record' => true] + $base,
    ], 'scarlett_control');
    expect($results[1]['result'])->toBeTrue()
        ->and(DB::table('consumer_records')->where('id', $subject)->exists())->toBeFalse()
        ->and(WorkflowRecord::forSubject('consumer', $subject)->count())->toBe(0);
});

it('deletes exact subject identities despite case and trailing-space insensitive database comparisons', function () {
    $engine = app(Engine::class);
    $target = new SubjectSlot('Document', '0', 'current');
    $otherSlots = [new SubjectSlot('document', '0', 'current'), new SubjectSlot('Document', '0 ', 'current')];
    $flow = $engine->replace($target, fn () => new Workflow([controlled('target')]));
    $others = array_map(fn ($slot) => $engine->replace($slot, fn () => new Workflow([controlled('other')])), $otherSlots);
    expect($engine->deleteSubject(new Subject('Document', '0'), fn () => true))->toBeTrue();
    expect(WorkflowRecord::whereKey($flow->id)->exists())->toBeFalse()->and($engine->current($target))->toBeNull();
    foreach ($otherSlots as $index => $slot) {
        expect($engine->current($slot))->toBe($others[$index]->id)->and($others[$index]->fresh()->status)->toBe('running');
    }
});

it('deletes current history even when the outer transaction predates admission or completion', function () {
    $subject = (string) Str::uuid();
    $engine = app(Engine::class);
    $old = $engine->start(new Workflow([controlled('complete-later')], 'consumer', $subject));
    config(['database.connections.concurrent' => config('database.connections.'.Environment::driver())]);
    DB::beginTransaction();
    try {
        expect((string) $old->steps()->sole()->state)->toBe('queued');
        $new = Environment::concurrentCommit(function () use ($old, $engine, $subject) {
            runStep($old->id, 'complete-later');

            return $engine->start(new Workflow([controlled('admit-later')], 'consumer', $subject));
        });
        expect($engine->deleteSubject(new Subject('consumer', $subject), function ($locked) use ($old, $new) {
            expect((string) $locked->firstWhere('id', $old->id)->steps->sole()->state)->toBe('completed');
            expect((string) $locked->firstWhere('id', $new->id)->steps->sole()->state)->toBe('queued');

            return true;
        }))->toBeTrue();
        DB::commit();
        expect(WorkflowRecord::whereKey([$old->id, $new->id])->count())->toBe(0);
    } finally {
        DB::setDefaultConnection(Environment::driver());
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::purge('concurrent');
    }
});
