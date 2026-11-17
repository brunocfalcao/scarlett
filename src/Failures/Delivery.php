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
            $this->publisher->publish(
                $pending,
                fn ($failure): bool => FailureRecord::whereKey($failure->id)->where('attempt', $failure->attempt)->where('status', Status::Queued)->whereNull('sent_at')
                    ->where(fn ($query) => $query->whereNull('publishing_until')->orWhere('publishing_until', '<=', now()))
                    ->update(['publishing_until' => now()->addSeconds((int) config('scarlett.delivery_retry_seconds'))]) > 0,
                function ($failure): void {
                    Bus::dispatch((new ExecuteFailure($failure->id, $failure->attempt, $failure->step->executionOptions()['worker_timeout']))
                        ->onConnection($failure->step->connection)->onQueue($failure->step->queue)->delay($failure->available_at));
                },
                fn ($failure): int => FailureRecord::whereKey($failure->id)->where('attempt', $failure->attempt)->update(['sent_at' => now(), 'publishing_until' => null]),
                fn ($failure): int => FailureRecord::whereKey($failure->id)->where('attempt', $failure->attempt)->update(['publishing_until' => null]),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
