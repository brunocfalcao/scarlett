<?php

declare(strict_types=1);

/**
 * Removes one business subject's workflow history and current-slot pointers together.
 *
 * Additional guard subjects let the application inspect related work under locks
 * before approving deletion. They are protected for the check, not deletion targets.
 * The application's callback can veto the whole operation, including its own writes.
 * Removing running records revokes future saves but cannot undo an external action
 * that a worker has already sent.
 */

namespace Scarlett;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Scarlett\Definition\Subject;
use Scarlett\Exceptions\DeletionVetoed;
use Scarlett\Models\StepRecord;
use Scarlett\Models\WorkflowRecord;

/**
 * Deletes a subject across all lanes after a transactional consumer veto check.
 * Admission is locked first; guarded subjects are inspected but never deleted.
 */
final class SubjectDeletion
{
    private const STEP_LOCK_CHUNK_SIZE = 500;

    /**
     * @param  callable(Collection<int, WorkflowRecord>): (bool|null)  $prepare
     * @param  list<Subject>  $guardSubjects  Additional subjects locked for veto checks only.
     */
    public function delete(Subject $subject, callable $prepare, array $guardSubjects = []): bool
    {
        foreach ($guardSubjects as $guard) {
            if (! $guard instanceof Subject) {
                throw new InvalidArgumentException('Deletion guards must be Subject definitions.');
            }
        }

        try {
            return DB::transaction(function () use ($subject, $prepare, $guardSubjects): bool {
                // Freeze admission/replacement before taking workflow and consumer locks.
                app(Admission::class)->lockExclusive();
                /**
                 * Read targets and guard subjects while new admission is blocked. Keep deletion
                 * IDs separate from the full callback collection so a related guard's history
                 * cannot be removed merely because the caller needed to inspect it.
                 */
                $locked = $this->lockWorkflows([$subject, ...$guardSubjects]);
                $targets = $locked->filter(fn (WorkflowRecord $workflow): bool => $subject->matches($workflow->subject_type, $workflow->subject_id))->modelKeys();
                $approved = $prepare($locked);
                if ($approved === null || $approved === false) {
                    throw new DeletionVetoed;
                }
                if ($approved !== true) {
                    throw new InvalidArgumentException('Subject deletion must return true, false or null.');
                }
                foreach ($targets as $id) {
                    // Removing the attempt records revokes ownership; no intermediate state survives deletion.
                    if (! app(History::class)->delete($id, includingRunning: true)) {
                        throw new RuntimeException('Revoked workflow history could not be deleted.');
                    }
                }
                $this->deleteSlots($subject);

                return true;
            }, (int) config('scarlett.transaction_attempts'));
        } catch (DeletionVetoed) {
            return false;
        }
    }

    /** @param list<Subject> $subjects */
    private function lockWorkflows(array $subjects): Collection
    {
        /**
         * Lock workflows in deterministic ID order, then compare subject strings exactly.
         * Some database collations consider different case or accents equal; the PHP check
         * keeps those distinct subjects from becoming accidental deletion targets.
         */
        $workflows = WorkflowRecord::where(function (Builder $query) use ($subjects): void {
            foreach ($subjects as $subject) {
                $query->orWhere(fn (Builder $query) => $query->forSubject($subject->subjectType, $subject->subjectId));
            }
        })->orderBy('id')->lockForUpdate()->get()->filter(
            fn (WorkflowRecord $workflow): bool => collect($subjects)->contains(
                fn (Subject $subject): bool => $subject->matches($workflow->subject_type, $workflow->subject_id),
            ),
        );
        /**
         * Step locks follow workflow order, then step ID, in bounded batches. Locking reads
         * stay current even if an enclosing MySQL transaction already has a snapshot.
         */
        $steps = new Collection;
        foreach (collect($workflows->modelKeys())->chunk(self::STEP_LOCK_CHUNK_SIZE) as $chunk) {
            $steps = $steps->merge(StepRecord::whereIn('workflow_id', $chunk)->orderBy('workflow_id')->orderBy('id')->lockForUpdate()->get());
        }
        $stepsByWorkflow = $steps->groupBy('workflow_id');
        foreach ($workflows as $workflow) {
            $workflow->setRelation('steps', $stepsByWorkflow->get($workflow->id, new Collection));
        }

        return $workflows;
    }

    private function deleteSlots(Subject $subject): void
    {
        // Exact comparison protects subjects that a database's collation considers equivalent.
        $ids = DB::table('scarlett_subject_slots')->where('subject_type', $subject->subjectType)
            ->where('subject_id', $subject->subjectId)->lockForUpdate()->get(['id', 'subject_type', 'subject_id'])
            ->filter(fn ($slot): bool => $subject->matches($slot->subject_type, $slot->subject_id))->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('scarlett_subject_slots')->whereIn('id', $ids)->delete();
        }
    }
}
