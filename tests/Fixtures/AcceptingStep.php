<?php

declare(strict_types=1);

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
