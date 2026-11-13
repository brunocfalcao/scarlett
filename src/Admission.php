<?php

declare(strict_types=1);

namespace Scarlett;

use Illuminate\Support\Facades\DB;
use Scarlett\Execution\Delivery;
use Scarlett\Execution\Progression;
use Scarlett\Models\WorkflowRecord;

final class Admission
{
    public function cooldown(): void
    {
        DB::transaction(function (): void {
            DB::table('scarlett_control')->where('id', 'admission')->lockForUpdate()->first();
            DB::table('scarlett_control')->where('id', 'admission')->update(['cooldown' => true]);
        });
    }

    public function reopen(): void
    {
        DB::transaction(function (): void {
            DB::table('scarlett_control')->where('id', 'admission')->lockForUpdate()->first();
            DB::table('scarlett_control')->where('id', 'admission')->update(['cooldown' => false]);
            foreach (WorkflowRecord::where('status', 'held')->lockForUpdate()->get() as $workflow) {
                $workflow->update(['status' => 'running', 'started_at' => now()]);
                app(Progression::class)->advance($workflow);
                app(Delivery::class)->afterCommit($workflow->id);
            }
        }, (int) config('scarlett.transaction_attempts'));
    }
}
