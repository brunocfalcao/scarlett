<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Scarlett\Jobs\ExecuteStep;
use Scarlett\Models\StepRecord;
use Scarlett\States\Phase;
use Throwable;

final class Delivery
{
    /** At-least-once transport; the worker's attempt claim suppresses duplicates. */
    public function flush(string $workflowId): void
    {
        $pending = DB::table('scarlett_deliveries')->join('scarlett_steps', 'scarlett_steps.id', '=', 'scarlett_deliveries.step_id')
            ->where('scarlett_steps.workflow_id', $workflowId)->whereNull('scarlett_deliveries.sent_at')
            ->orderByDesc('scarlett_steps.priority')->orderBy('scarlett_deliveries.created_at')
            ->select('scarlett_deliveries.*')->get();
        foreach ($pending as $delivery) {
            $step = StepRecord::find($delivery->step_id);
            if (! $step || $step->attempt !== $delivery->attempt || $step->state::phase() !== Phase::Queued) {
                continue;
            }
            $claimed = DB::table('scarlett_deliveries')->where('id', $delivery->id)->whereNull('sent_at')
                ->where(fn ($query) => $query->whereNull('publishing_until')->orWhere('publishing_until', '<=', now()))
                ->update(['publishing_until' => now()->addSeconds((int) config('scarlett.delivery_retry_seconds'))]);
            if ($claimed === 0) {
                continue;
            }
            try {
                Bus::dispatch((new ExecuteStep($step->id, $delivery->attempt))
                    ->onConnection($step->connection)->onQueue($step->queue)->delay($step->available_at));
                DB::table('scarlett_deliveries')->where('id', $delivery->id)->update(['sent_at' => now(), 'publishing_until' => null, 'updated_at' => now()]);
            } catch (Throwable $exception) {
                // The intent stays durable. Do not turn a transport failure into a repeated business action.
                DB::table('scarlett_deliveries')->where('id', $delivery->id)->update(['publishing_until' => null]);
                report($exception);
            }
        }
    }
}
