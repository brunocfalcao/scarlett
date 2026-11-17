<?php

declare(strict_types=1);

/**
 * Maintains one current workflow pointer for a subject's named lane.
 *
 * For example, order 42 can have separate "pricing" and "notification" slots.
 * Replacing one slot cancels its previous workflow and saves its replacement
 * atomically. A caller may veto replacement; an exception rolls back even the
 * cancellation before the public method translates that veto into a null result.
 */

namespace Scarlett;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Exceptions\ReplacementVetoed;
use Scarlett\Models\WorkflowRecord;

/**
 * Serializes replacement of one named subject lane and preserves its current pointer.
 * A veto escapes the transaction before being converted to a null result.
 */
final class WorkflowSlots
{
    /** Invoke before acquiring application locks; callback writes must share this connection and be replay-safe. */
    public function replace(SubjectSlot $slot, callable $prepare): ?WorkflowRecord
    {
        try {
            return DB::transaction(function () use ($slot, $prepare): WorkflowRecord {
                // Admission always precedes workflow locks, including held-work reopening.
                app(Admission::class)->lockShared();
                // Updating the duplicate key takes an exclusive lock, avoiding shared-lock upgrade races.
                DB::table('scarlett_subject_slots')->upsert([
                    'id' => $slot->id(), 'subject_type' => $slot->subjectType, 'subject_id' => $slot->subjectId,
                    'name' => $slot->name, 'workflow_id' => null,
                ], ['id'], ['id']);
                $record = DB::table('scarlett_subject_slots')->where('id', $slot->id())->lockForUpdate()->first();
                $previous = $record->workflow_id === null ? null : WorkflowRecord::whereKey($record->workflow_id)->lockForUpdate()->first();
                if ($previous !== null) {
                    app(Engine::class)->cancelWorkflow($previous->id);
                    $previous->refresh();
                }
                /**
                 * Let the application inspect the previous workflow and prepare related database
                 * changes while all ownership locks are held. False or null means keep the old
                 * workflow and pointer exactly as they were, so throw to roll the transaction back.
                 */
                $definition = $prepare($previous);
                if ($definition === null || $definition === false) {
                    throw new ReplacementVetoed;
                }
                if (! $definition instanceof Workflow) {
                    throw new InvalidArgumentException('Replacement must return a Workflow, null or false.');
                }
                /**
                 * The slot already decides which business subject owns this workflow. Accept an
                 * omitted subject and fill it from the slot, but reject an explicitly different
                 * subject so the current pointer cannot silently cross ownership boundaries.
                 */
                if (($definition->subjectType !== null && $definition->subjectType !== $slot->subjectType)
                    || ($definition->subjectId !== null && $definition->subjectId !== $slot->subjectId)) {
                    throw new InvalidArgumentException('Replacement workflow subject must match its slot.');
                }
                $workflow = app(Engine::class)->start(new Workflow($definition->nodes, $slot->subjectType, $slot->subjectId, $definition->metadata,
                    title: $definition->title, description: $definition->description, canonical: $definition->canonical));
                DB::table('scarlett_subject_slots')->where('id', $slot->id())->update(['workflow_id' => $workflow->id]);

                return $workflow;
            }, (int) config('scarlett.transaction_attempts'));
        } catch (ReplacementVetoed) {
            return null;
        }
    }

    public function current(SubjectSlot $slot): ?string
    {
        return $this->currentForSubjects($slot->subjectType, [$slot->subjectId], $slot->name)[$slot->subjectId] ?? null;
    }

    /**
     * @param  array<array-key, string|int>  $subjectIds
     * @return array<string|int, string>
     */
    public function currentForSubjects(string $subjectType, array $subjectIds, string $slotName): array
    {
        /**
         * Build exact slot identities for the requested subjects, then query them together.
         * The returned map uses caller-facing subject IDs rather than internal slot hashes;
         * subjects without an existing referenced workflow are omitted from the result.
         */
        $subjectsBySlot = [];
        foreach ($subjectIds as $subjectId) {
            if (! is_string($subjectId) && ! is_int($subjectId)) {
                throw new InvalidArgumentException('Subject IDs must be strings or integers.');
            }
            $slot = new SubjectSlot($subjectType, (string) $subjectId, $slotName);
            $subjectsBySlot[$slot->id()] = $slot->subjectId;
        }
        if ($subjectsBySlot === []) {
            return [];
        }

        // Hashed identities preserve exact matching independently of database collation.
        $assignments = DB::table('scarlett_subject_slots')
            ->join('scarlett_workflows', 'scarlett_workflows.id', '=', 'scarlett_subject_slots.workflow_id')
            ->whereIn('scarlett_subject_slots.id', array_keys($subjectsBySlot))
            ->pluck('scarlett_workflows.id as workflow_id', 'scarlett_subject_slots.id as slot_id');
        $current = [];
        foreach ($subjectsBySlot as $slotId => $subjectId) {
            if ($assignments->has($slotId)) {
                $current[$subjectId] = $assignments->get($slotId);
            }
        }

        return $current;
    }
}
