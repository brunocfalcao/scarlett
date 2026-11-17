<?php

declare(strict_types=1);

/**
 * A string-backed enum used to prove payload restoration preserves enum classes.
 * It is registered by alias in each relevant test rather than globally available
 * to every payload decoder.
 */

namespace Scarlett\Tests\Fixtures;

enum StringStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
}
