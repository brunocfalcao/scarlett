<?php

declare(strict_types=1);

/**
 * Tracks the two parts of a parent's runtime-child handoff.
 *
 * Waiting means the child plan is still running. Verifying means the children have
 * finished and the parent is queued to check its effective result. Persisting this
 * distinction lets another worker resume without creating or running the plan again.
 */

namespace Scarlett\Execution;

/** Runtime handoff state survives retries, worker loss and verification delivery. */
enum ChildPhase: string
{
    case Waiting = 'waiting';
    case Verifying = 'verifying';
}
