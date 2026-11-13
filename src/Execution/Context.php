<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Scarlett\Engine;

final readonly class Context
{
    public function __construct(public string $workflowId, public string $stepId, public string $attempt, public int $failures) {}

    public function heartbeat(): bool
    {
        return app(Engine::class)->heartbeat($this->stepId, $this->attempt);
    }

    public function isCurrent(): bool
    {
        return app(Engine::class)->isCurrent($this->stepId, $this->attempt);
    }
}
