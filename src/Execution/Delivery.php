<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Scarlett\Jobs\ExecuteStep;
use Scarlett\Models\StepRecord;
use Scarlett\States\Phase;
use Throwable;

final class Delivery
{
    public function afterCommit(string $workflowId): void
    {
        DB::afterCommit(fn () => $this->flush($workflowId));
    }

    /** At-least-once transport; the worker's attempt claim suppresses duplicates. */
    public function flush(string $workflowId): void
    {
        try {
            $this->publish($workflowId);
        } catch (Throwable $exception) {
            // Publication is recoverable even when its lookup or lease claim fails.
            report($exception);
        }
        app(\Scarlett\Failures\Delivery::class)->flush($workflowId);
    }

    private function publish(string $workflowId): void
    {
        $pending = DB::table('scarlett_deliveries')->join('scarlett_steps', 'scarlett_steps.id', '=', 'scarlett_deliveries.step_id')
            ->where('scarlett_steps.workflow_id', $workflowId)->whereNull('scarlett_deliveries.sent_at')
            ->whereColumn('scarlett_steps.attempt', 'scarlett_deliveries.attempt')
            ->orderByDesc('scarlett_steps.priority')->orderBy('scarlett_deliveries.created_at')
            ->select('scarlett_deliveries.*')->get();
        if ($pending->isEmpty()) {
            return;
        }
        $steps = StepRecord::whereKey($pending->pluck('step_id'))->get()->keyBy('id');
        foreach ($pending as $delivery) {
            $step = $steps->get($delivery->step_id);
            if (! $step || $step->attempt !== $delivery->attempt || $step->state::phase() !== Phase::Queued) {
                continue;
            }
            $claimed = $this->unclaimed()->where('id', $delivery->id)->whereNull('sent_at')
                ->update(['publishing_until' => now()->addSeconds((int) config('scarlett.delivery_retry_seconds'))]);
            if ($claimed === 0) {
                continue;
            }
            try {
                Bus::dispatch((new ExecuteStep($step->id, $delivery->attempt, $step->executionOptions()['worker_timeout']))
                    ->onConnection($step->connection)->onQueue($step->queue)->delay($step->available_at));
                DB::table('scarlett_deliveries')->where('id', $delivery->id)->update(['sent_at' => now(), 'publishing_until' => null, 'updated_at' => now()]);
            } catch (Throwable $exception) {
                // The intent stays durable. Do not turn a transport failure into a repeated business action.
                DB::table('scarlett_deliveries')->where('id', $delivery->id)->update(['publishing_until' => null]);
                report($exception);
            }
        }
    }

    public function replayable(StepRecord $step): Builder
    {
        return $this->unclaimed()->where('step_id', $step->id)->where('attempt', $step->attempt)
            ->where(fn ($query) => $query->whereNull('sent_at')->orWhere('sent_at', '<=', now()->subSeconds((int) config('scarlett.delivery_retry_seconds'))));
    }

    private function unclaimed(): Builder
    {
        return DB::table('scarlett_deliveries')
            ->where(fn ($query) => $query->whereNull('publishing_until')->orWhere('publishing_until', '<=', now()));
    }
}
