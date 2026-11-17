<?php

declare(strict_types=1);

/**
 * Controls precheck acceptance, exception classification, and verification failure
 * without changing the surrounding workflow. Separate action and verification counters
 * show exactly which lifecycle methods repeat and which must remain single-action.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Step;
use Throwable;

class LifecycleEdgeStep extends Step
{
    public static int $actions = 0;

    public static int $verifications = 0;

    public function __construct(
        public string $mode = 'before-complete',
        public mixed $value = 'ok',
    ) {}

    public function before(Context $context): ?Decision
    {
        return match ($this->mode) {
            'before-complete' => Decision::complete($this->value),
            default => null,
        };
    }

    public function handle(): mixed
    {
        self::$actions++;
        if (in_array($this->mode, ['exception-skip', 'exception-stop'], true)) {
            throw new \RuntimeException('Action threw for exception decision.');
        }

        return $this->value;
    }

    public function onException(Throwable $exception, Context $context): Decision
    {
        return match ($this->mode) {
            'exception-skip' => Decision::skip(),
            'exception-stop' => Decision::stop('Stopped by onException.'),
            default => parent::onException($exception, $context),
        };
    }

    public function verify(Context $context, mixed $result): bool
    {
        self::$verifications++;
        if ($this->mode === 'verify-throw') {
            throw new \RuntimeException('Verification exploded.');
        }

        return true;
    }
}
