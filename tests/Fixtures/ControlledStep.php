<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Exceptions\Throttled;
use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Step;

class ControlledStep extends Step
{
    public static array $actions = [];

    public static array $constructed = [];

    public function __construct(public string $name, public string $mode = 'success', public mixed $value = null)
    {
        self::$constructed[] = [$name, $value];
    }

    public function before(Context $context): ?Decision
    {
        return match ($this->mode) {
            'skip' => Decision::skip(), 'stop' => Decision::stop(), 'fail' => Decision::fail(), default => null,
        };
    }

    public function handle(): mixed
    {
        self::$actions[$this->name] = (self::$actions[$this->name] ?? 0) + 1;
        if ($this->mode === 'throw') {
            throw new \RuntimeException('test failure');
        }
        if ($this->mode === 'throttle') {
            throw new Throttled(10);
        }

        return $this->value;
    }

    public function verify(Context $context, mixed $result): bool
    {
        return $this->mode !== 'verify';
    }
}
