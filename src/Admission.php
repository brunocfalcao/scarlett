<?php

declare(strict_types=1);

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
            DB::table('scarlett_control')->where('id', 'admission')->update(['cooldown' => true]);
        });
    }

    public function reopen(): void
    {
        DB::transaction(function (): void {
            $this->lockExclusive();
            DB::table('scarlett_control')->where('id', 'admission')->update(['cooldown' => false]);
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
