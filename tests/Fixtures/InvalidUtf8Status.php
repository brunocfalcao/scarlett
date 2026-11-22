<?php

declare(strict_types=1);

/**
 * A string-backed enum whose backing value is not valid UTF-8. It proves enum values
 * are checked at encode time like other stored text, instead of failing at the JSON write.
 */

namespace Scarlett\Tests\Fixtures;

enum InvalidUtf8Status: string
{
    case Broken = "\xB1";
}
