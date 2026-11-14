<?php

declare(strict_types=1);

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
