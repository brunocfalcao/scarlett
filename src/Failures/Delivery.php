<?php

declare(strict_types=1);

/**
 * Publishes jobs for terminal failure hooks after their records are committed.
 *
 * The failure record itself holds its delivery intent, attempt token, and publisher
 * lease. Conditional updates claim only the currently queued attempt. Reuse the
 * original step's queue connection, queue name, and saved timeout so failure work
 * uses the same execution environment. Queue errors are reported and left recoverable.
 */

namespace Scarlett\Failures;

use Illuminate\Support\Facades\Bus;
use Scarlett\Execution\OutboxPublisher;
use Scarlett\Jobs\ExecuteFailure;
use Scarlett\Models\FailureRecord;
use Throwable;

/**
 * Publishes terminal-hook attempts using the original step routing and timeout.
 * A failed publish leaves the failure record recoverable by the recovery command.
 */
final class Delivery
{
    public function __construct(private OutboxPublisher $publisher = new OutboxPublisher) {}

    public function flush(string $workflowId): void
    {
        try {
            $pending = FailureRecord::where('workflow_id', $workflowId)->where('status', Status::Queued)->whereNull('sent_at')->with('step')->get();
            /**
             * Each claim records its own lease expiry. Sent and release clear only that lease, so a
             * publisher whose lease expired cannot erase the newer lease of a later publisher.
             */
            $leases = [];
            $this->publisher->publish(
                $pending,
                function ($failure) use (&$leases): bool {
                    $lease = now()->addSeconds((int) config('scarlett.delivery_retry_seconds'));
                    $claimed = FailureRecord::whereKey($failure->id)->where('attempt', $failure->attempt)->where('status', Status::Queued)->whereNull('sent_at')
                        ->where(fn ($query) => $query->whereNull('publishing_until')->orWhere('publishing_until', '<=', now()))
                        ->update(['publishing_until' => $lease]) > 0;
                    if ($claimed) {
                        $leases[$failure->id] = $lease;
                    }

                    return $claimed;
                },
                function ($failure): void {
                    Bus::dispatch((new ExecuteFailure($failure->id, $failure->attempt, $failure->step->executionOptions()['worker_timeout']))
                        ->onConnection($failure->step->connection)->onQueue($failure->step->queue)->delay($failure->available_at));
                },
                function ($failure) use (&$leases): int {
                    if (! isset($leases[$failure->id])) {
                        return 0;
                    }

                    return FailureRecord::whereKey($failure->id)->where('attempt', $failure->attempt)->where('publishing_until', $leases[$failure->id])
                        ->update(['sent_at' => now(), 'publishing_until' => null]);
                },
                function ($failure) use (&$leases): int {
                    if (! isset($leases[$failure->id])) {
                        return 0;
                    }

                    return FailureRecord::whereKey($failure->id)->where('attempt', $failure->attempt)->where('publishing_until', $leases[$failure->id])
                        ->update(['publishing_until' => null]);
                },
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
