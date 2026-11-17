<?php

declare(strict_types=1);

/**
 * Checks exact batched current-slot reads against the selected real database driver.
 *
 * Case, accents, trailing spaces, and slot names can collide under text collation.
 * SubjectSlot hashes must keep those identities separate while one query returns
 * only the requested existing assignments. This complements in-memory feature tests
 * that cannot prove a server's comparison behavior.
 */

use Illuminate\Support\Facades\DB;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Engine;

it('reads exact subject and slot identities in one query despite database collation equivalence', function () {
    $engine = app(Engine::class);
    $identities = [
        ['Document', '0', 'current'],
        ['document', '0', 'current'],
        ['Document', '0 ', 'current'],
        ['Document', '0', 'CURRENT'],
        ['Document', 'café', 'current'],
        ['Document', 'cafe', 'current'],
    ];
    $flows = array_map(fn ($identity) => $engine->replace(new SubjectSlot(...$identity), fn () => new Workflow([controlled('work')])), $identities);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect($engine->currentForSubjects('Document', [0, '0 ', 'café', 'cafe', 'missing'], 'current'))
            ->toBe([0 => $flows[0]->id, '0 ' => $flows[2]->id, 'café' => $flows[4]->id, 'cafe' => $flows[5]->id]);
        expect(DB::getQueryLog())->toHaveCount(1);
    } finally {
        DB::disableQueryLog();
    }
    expect($engine->currentForSubjects('document', [0], 'current'))->toBe([0 => $flows[1]->id])
        ->and($engine->currentForSubjects('Document', [0], 'CURRENT'))->toBe([0 => $flows[3]->id]);
});
