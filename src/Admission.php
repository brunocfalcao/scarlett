<?php

declare(strict_types=1);

/**
 * Coordinates starting workflows with operations that must see a stable set of work.
 *
 * The single control row is a database gate: producers share it, while subject
 * deletion takes it exclusively. All callers acquire this gate before workflow or
 * application locks. That consistent order avoids competing operations each holding
 * the lock the other needs. A missing control row is an error, not an open gate.
 */

namespace Scarlett;

use Illuminate\Support\Facades\DB;
use Scarlett\Execution\Delivery;
use Scarlett\Execution\Progression;
use Scarlett\Models\WorkflowRecord;

/**
 * Orders workflow admission before consumer writes and global deletion.
 * Shared admission permits producers together; exclusive operations wait for them.
 */
final class Admission
{
    /** Acquire admission before workflow or consumer locks; callbacks must be replay-safe database work. */
    public function transaction(callable $operation): mixed
    {
        app(Configuration::class)->validate();

        /**
         * Keep the gate and the caller's database changes in one transaction. The database
         * may retry the callback after lock contention, so it must not send external requests
         * or perform other effects that cannot be rolled back and safely repeated.
         */
        return DB::transaction(function () use ($operation): mixed {
            $this->lockShared();

            return $operation();
        }, (int) config('scarlett.transaction_attempts'));
    }

    public function lockShared(): object
    {
        return DB::table('scarlett_control')->where('id', 'admission')->sharedLock()->sole();
    }

    public function lockExclusive(): object
    {
        return DB::table('scarlett_control')->where('id', 'admission')->lockForUpdate()->sole();
    }

    public function cooldown(): void
    {
        DB::transaction(function (): void {
            $this->lockExclusive();
            /**
             * Hold future workflows at registration. Existing workflows keep their started_at
             * value and can continue; this switch is not a pause command for running workers.
             */
            DB::table('scarlett_control')->where('id', 'admission')->update(['cooldown' => true]);
        });
    }

    public function reopen(): void
    {
        DB::transaction(function (): void {
            $this->lockExclusive();
            DB::table('scarlett_control')->where('id', 'admission')->update(['cooldown' => false]);
            /**
             * Release held workflows under the same admission gate used by producers. Each
             * workflow gets its first eligibility check now; batch publication callbacks after
             * the transaction so queue workers can see the complete committed state.
             */
            $workflowIds = [];
            foreach (WorkflowRecord::where('status', 'held')->orderBy('id')->lockForUpdate()->get() as $workflow) {
                $workflow->update(['status' => 'running', 'started_at' => now()]);
                app(Progression::class)->advance($workflow);
                $workflowIds[] = $workflow->id;
            }
            if ($workflowIds !== []) {
                app(Delivery::class)->afterCommitMany($workflowIds);
            }
        }, (int) config('scarlett.transaction_attempts'));
    }
}
