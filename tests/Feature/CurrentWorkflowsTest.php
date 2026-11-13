<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;
use Scarlett\History;

beforeEach(function () {
    Queue::fake();
});

it('reads a page of current assignments in one query without leaking other slots or subjects', function () {
    $engine = app(Engine::class);
    $zero = new SubjectSlot('document', '0', 'current');
    $old = $engine->replace($zero, fn () => new Workflow([controlled('old')]));
    $replacement = $engine->replace($zero, fn () => new Workflow([controlled('replacement')]));
    $padded = $engine->replace(new SubjectSlot('document', '01', 'current'), fn () => new Workflow([controlled('padded')]));
    $engine->replace(new SubjectSlot('document', '0', 'mail'), fn () => new Workflow([controlled('mail')]));
    $engine->replace(new SubjectSlot('other', '0', 'current'), fn () => new Workflow([controlled('other')]));
    $engine->replace(new SubjectSlot('document', 'unrequested', 'current'), fn () => new Workflow([controlled('unrequested')]));
    expect($engine->current($zero))->toBe($replacement->id)->and($old->fresh()->status)->toBe('cancelled');
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect($engine->currentForSubjects('document', [0, '01', '0', 'missing'], 'current'))
            ->toBe([0 => $replacement->id, '01' => $padded->id]);
        expect(DB::getQueryLog())->toHaveCount(1);
    } finally {
        DB::disableQueryLog();
    }
});

it('keeps held completed and archived assignments and omits missing or deleted workflows', function () {
    $engine = app(Engine::class);
    $completed = $engine->replace(new SubjectSlot('document', 'done', 'current'), fn () => new Workflow([controlled('done')]));
    runStep($completed->id, 'done');
    $archived = $engine->replace(new SubjectSlot('document', 'archived', 'current'), fn () => new Workflow([controlled('archived')]));
    runStep($archived->id, 'archived');
    app(History::class)->archive($archived->id);
    $deleted = $engine->replace(new SubjectSlot('document', 'deleted', 'current'), fn () => new Workflow([controlled('deleted')]));
    runStep($deleted->id, 'deleted');
    app(History::class)->delete($deleted->id);
    $engine->cooldown();
    $held = $engine->replace(new SubjectSlot('document', 'held', 'current'), fn () => new Workflow([controlled('held')]));
    expect($engine->currentForSubjects('document', ['held', 'done', 'archived', 'deleted', 'missing'], 'current'))
        ->toBe(['held' => $held->id, 'done' => $completed->id, 'archived' => $archived->id]);
});

it('returns an empty mapping without a database query for an empty page', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(app(Engine::class)->currentForSubjects('document', [], 'current'))->toBe([]);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});

it('rejects invalid subject identities before querying', function (mixed $invalid) {
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect(fn () => app(Engine::class)->currentForSubjects('document', ['valid', $invalid], 'current'))->toThrow(InvalidArgumentException::class);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
})->with([
    'null' => [null],
    'boolean' => [false],
    'float' => [1.5],
    'empty string' => [''],
    'invalid UTF-8' => ["\xFF"],
    'array' => [[]],
]);
