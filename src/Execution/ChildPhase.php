<?php

declare(strict_types=1);

namespace Scarlett\Execution;

/** Runtime handoff state survives retries, worker loss and verification delivery. */
enum ChildPhase: string
{
    case Waiting = 'waiting';
    case Verifying = 'verifying';
}
