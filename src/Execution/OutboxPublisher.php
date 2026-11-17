<?php

declare(strict_types=1);

/**
 * Implements the common publication sequence for step and failure-handler jobs.
 *
 * An outbox is a saved request to send a job after the state change commits. Claim
 * each request, dispatch it, then mark it sent. On a transport error, release the
 * publishing lease and report the problem so recovery can try later. Dispatch and
 * mark-sent are separate operations: duplicate jobs are possible and must be safe.
 */

namespace Scarlett\Execution;

use Throwable;

/** Runs the shared claim, dispatch and lease-release protocol for outbox rows. */
final class OutboxPublisher
{
    /**
     * @param  iterable<mixed>  $records
     * @param  callable(mixed): bool  $claim
     * @param  callable(mixed): void  $dispatch
     * @param  callable(mixed): void  $sent
     * @param  callable(mixed): void  $release
     */
    public function publish(iterable $records, callable $claim, callable $dispatch, callable $sent, callable $release): void
    {
        foreach ($records as $record) {
            /**
             * Another publisher may already own this request or it may no longer be current.
             * Skip it without dispatching; a successful claim is the permission to send.
             */
            if (! $claim($record)) {
                continue;
            }
            try {
                $dispatch($record);
                $sent($record);
            } catch (Throwable $exception) {
                $release($record);
                report($exception);
            }
        }
    }
}
