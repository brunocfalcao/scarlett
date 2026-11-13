<?php

declare(strict_types=1);

namespace Scarlett;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Scarlett\Definition\SubjectSlot;
use Scarlett\Definition\Workflow;
use Scarlett\Exceptions\ReplacementVetoed;
use Scarlett\Models\WorkflowRecord;

final class WorkflowSlots
{
    /** Invoke before acquiring application locks; callback writes must share this connection and be replay-safe. */
    public function replace(SubjectSlot $slot, callable $prepare): ?WorkflowRecord
    {
        try {
            return DB::transaction(function () use ($slot, $prepare): WorkflowRecord {
                // Admission always precedes workflow locks, including held-work reopening.
                DB::table('scarlett_control')->where('id', 'admission')->sharedLock()->first();
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
                $definition = $prepare($previous);
                if ($definition === null || $definition === false) {
                    throw new ReplacementVetoed;
                }
                if (! $definition instanceof Workflow) {
                    throw new InvalidArgumentException('Replacement must return a Workflow, null or false.');
                }
                if (($definition->subjectType !== null && $definition->subjectType !== $slot->subjectType)
                    || ($definition->subjectId !== null && $definition->subjectId !== $slot->subjectId)) {
                    throw new InvalidArgumentException('Replacement workflow subject must match its slot.');
                }
                $workflow = app(Engine::class)->start(new Workflow($definition->nodes, $slot->subjectType, $slot->subjectId, $definition->metadata));
                DB::table('scarlett_subject_slots')->where('id', $slot->id())->update(['workflow_id' => $workflow->id]);

                return $workflow;
            }, (int) config('scarlett.transaction_attempts'));
        } catch (ReplacementVetoed) {
            return null;
        }
    }

    public function current(SubjectSlot $slot): ?string
    {
        return DB::table('scarlett_subject_slots')->join('scarlett_workflows', 'scarlett_workflows.id', '=', 'scarlett_subject_slots.workflow_id')
            ->where('scarlett_subject_slots.id', $slot->id())->value('scarlett_workflows.id');
    }
}
