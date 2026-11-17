<?php

declare(strict_types=1);

/**
 * Throws from the business action, then accepts the exception through AcceptException.
 * The action counter and controllable verification flag prove verification retries do
 * not call the throwing action again after its accepted result has been saved.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Step;

final class AcceptingStep extends Step
{
    public static int $actions = 0;

    public static bool $verified = false;

    public function handle(): never
    {
        self::$actions++;
        throw new \RuntimeException('Already performed.');
    }

    public function onException(\Throwable $exception, Context $context): Decision
    {
        return app(AcceptException::class)->decide($exception, $context);
    }

    public function verify(Context $context, mixed $result): bool
    {
        return self::$verified;
    }
}
