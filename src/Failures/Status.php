<?php

declare(strict_types=1);

/**
 * Tracks the failure handler independently from its already-finished business step.
 *
 * Pending waits for ordinary descendants; Queued waits for a worker; Running owns
 * a lease. Completed, Failed, and Cancelled are final handler outcomes. A failed
 * hook remains failed history, while its original step keeps its own failure cause.
 */

namespace Scarlett\Failures;

/**
 * Lifecycle of a terminal hook, independent from the already-settled step state.
 */
enum Status: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function settled(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
