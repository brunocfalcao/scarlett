<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

enum StringStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
}
