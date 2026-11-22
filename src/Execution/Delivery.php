<?php

declare(strict_types=1);

/**
 * Sends committed step delivery requests to Laravel's queue.
 *
 * Progression first saves an outbox row, meaning "send this attempt". Only after
 * the transaction commits does this class publish a job. A short publishing lease
 * keeps two publishers from deliberately sending the same row simultaneously.
 * Sending can still succeed just before a publisher crashes, so duplicate delivery
 * is expected; Engine::claim() decides whether the worker may actually run it.
 */

namespace Scarlett\Execution;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Scarlett\Jobs\ExecuteStep;
use Scarlett\Models\StepRecord;
use Scarlett\States\Phase;
use Throwable;

/**
 * Publishes committed delivery intents without coupling transport success to action success.
 * Publishing leases limit duplicate publication; attempt claims fence duplicate execution.
 */
final class Delivery
{
    public function __construct(private OutboxPublisher $publisher = new OutboxPublisher) {}

    public function afterCommit(string $workflowId): void
    {
        $this->afterCommitMany([$workflowId]);
    }

    /** @param list<string> $workflowIds */
    public function afterCommitMany(array $workflowIds): void
    {
        DB::afterCommit(function () use ($workflowIds): void {
            foreach ($workflowIds as $workflowId) {
                $this->flush($workflowId);
            }
        });
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
        /**
         * Only current attempt tokens may be published. Old unsent outbox rows can remain
         * after cancellation or retry; joining on the step's current token excludes them.
         * Priority orders publication, while the saved available_at controls job delay.
         */
        $pending = DB::table('scarlett_deliveries')->join('scarlett_steps', 'scarlett_steps.id', '=', 'scarlett_deliveries.step_id')
            ->where('scarlett_steps.workflow_id', $workflowId)->whereNull('scarlett_deliveries.sent_at')
            ->whereColumn('scarlett_steps.attempt', 'scarlett_deliveries.attempt')
            ->orderByDesc('scarlett_steps.priority')->orderBy('scarlett_deliveries.created_at')
            ->select('scarlett_deliveries.*')->get();
        if ($pending->isEmpty()) {
            return;
        }
        $steps = StepRecord::whereKey($pending->pluck('step_id'))->get()->keyBy('id');
        /**
         * Each claim records its own lease expiry. Sent and release clear only that lease, so a
         * publisher whose lease expired cannot erase the newer lease of a later publisher.
         */
        $leases = [];
        $this->publisher->publish(
            $pending,
            function ($delivery) use ($steps, &$leases): bool {
                $step = $steps->get($delivery->step_id);
                if (! $step || $step->attempt !== $delivery->attempt || $step->state::phase() !== Phase::Queued) {
                    return false;
                }
                $lease = now()->addSeconds((int) config('scarlett.delivery_retry_seconds'));
                $claimed = $this->unclaimed()->where('id', $delivery->id)->whereNull('sent_at')
                    ->update(['publishing_until' => $lease]) > 0;
                if ($claimed) {
                    $leases[$delivery->id] = $lease;
                }

                return $claimed;
            },
            function ($delivery) use ($steps): void {
                $step = $steps->get($delivery->step_id);
                Bus::dispatch((new ExecuteStep($step->id, $delivery->attempt, $step->executionOptions()['worker_timeout']))
                    ->onConnection($step->connection)->onQueue($step->queue)->delay($step->available_at));
            },
            function ($delivery) use (&$leases): int {
                if (! isset($leases[$delivery->id])) {
                    return 0;
                }

                return DB::table('scarlett_deliveries')->where('id', $delivery->id)->where('publishing_until', $leases[$delivery->id])
                    ->update(['sent_at' => now(), 'publishing_until' => null, 'updated_at' => now()]);
            },
            function ($delivery) use (&$leases): int {
                if (! isset($leases[$delivery->id])) {
                    return 0;
                }

                return DB::table('scarlett_deliveries')->where('id', $delivery->id)->where('publishing_until', $leases[$delivery->id])
                    ->update(['publishing_until' => null]);
            },
        );
    }

    /**
     * A sent row becomes replayable only after its retry grace has elapsed from the moment
     * it was due, so a delayed job is not replayed while it is still waiting to run.
     */
    public function replayable(StepRecord $step): Builder
    {
        return $this->replayableRows()->where('step_id', $step->id)->where('attempt', $step->attempt);
    }

    /**
     * Every outbox row that recovery may replay now. Scans use this as a correlated subquery,
     * so the step and the delivery are judged by the same predicate.
     */
    public function replayableRows(): Builder
    {
        $cutoff = now()->subSeconds((int) config('scarlett.delivery_retry_seconds'));

        return $this->unclaimed()
            ->where(fn ($query) => $query->whereNull('sent_at')
                ->orWhere(fn ($sent) => $sent->where('sent_at', '<=', $cutoff)->where('available_at', '<=', $cutoff)));
    }

    private function unclaimed(): Builder
    {
        return DB::table('scarlett_deliveries')
            ->where(fn ($query) => $query->whereNull('publishing_until')->orWhere('publishing_until', '<=', now()));
    }
}
