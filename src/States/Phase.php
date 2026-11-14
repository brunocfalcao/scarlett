<?php

declare(strict_types=1);

namespace Scarlett\States;

/**
 * Engine scheduling categories shared by built-in and consumer-defined states.
 */
enum Phase: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Children = 'children';
    case Settled = 'settled';
}
