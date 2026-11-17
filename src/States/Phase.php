<?php

declare(strict_types=1);

/**
 * Groups states by the scheduling behavior the engine needs to apply.
 *
 * Pending has no delivery yet; Queued has an attempt waiting for a worker; Running
 * has a claimed lease; Children waits for a subtree; Settled is final. Custom states
 * reuse these phases so scheduling does not need to know each application's labels.
 */

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
