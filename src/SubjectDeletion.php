<?php

declare(strict_types=1);

namespace Scarlett;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Scarlett\Definition\Subject;
use Scarlett\Exceptions\DeletionVetoed;
use Scarlett\Models\WorkflowRecord;

final class SubjectDeletion
{
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
                DB::table('scarlett_control')->where('id', 'admission')->lockForUpdate()->sole();
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
        $workflows = WorkflowRecord::where(function (Builder $query) use ($subjects): void {
            foreach ($subjects as $subject) {
                $query->orWhere(fn (Builder $query) => $query->forSubject($subject->subjectType, $subject->subjectId));
            }
        })->orderBy('id')->lockForUpdate()->get()->filter(
            fn (WorkflowRecord $workflow): bool => collect($subjects)->contains(
                fn (Subject $subject): bool => $subject->matches($workflow->subject_type, $workflow->subject_id),
            ),
        );
        foreach ($workflows as $workflow) {
            // Locking reads stay current even if an enclosing MySQL transaction already has a snapshot.
            $workflow->setRelation('steps', $workflow->steps()->orderBy('id')->lockForUpdate()->get());
        }

        return $workflows;
    }

    private function deleteSlots(Subject $subject): void
    {
        // Exact comparison protects subjects that a database's collation considers equivalent.
        $ids = DB::table('scarlett_subject_slots')->where('subject_type', $subject->subjectType)
            ->where('subject_id', $subject->subjectId)->lockForUpdate()->get(['id', 'subject_type', 'subject_id'])
            ->filter(fn ($slot): bool => $subject->matches($slot->subject_type, $slot->subject_id))->pluck('id');
        DB::table('scarlett_subject_slots')->whereIn('id', $ids)->delete();
    }
}
