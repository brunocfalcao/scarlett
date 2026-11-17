<?php

declare(strict_types=1);

/**
 * Returns a mutable date in a named timezone, then records the type verification
 * actually receives. Rejecting the first verification forces a second delivery,
 * proving both reads use the decoded saved result rather than rerunning the action.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;

class DateResultStep extends ControlledStep
{
    public static array $verifiedTypes = [];

    public function handle(): mixed
    {
        parent::handle();

        return new \DateTime('2026-10-01 12:00:00', new \DateTimeZone('Europe/Lisbon'));
    }

    public function verify(Context $context, mixed $result): bool
    {
        self::$verifiedTypes[] = $result::class;

        return count(self::$verifiedTypes) > 1;
    }
}
