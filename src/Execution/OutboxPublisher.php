<?php

declare(strict_types=1);

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
