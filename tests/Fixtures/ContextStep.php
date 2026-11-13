<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Models\WorkflowRecord;
use Scarlett\Step;
use Throwable;

final class ContextStep extends Step
{
    public function __construct(private Context $context, private bool $fail = false) {}

    public function handle(Context $context): bool
    {
        if ($context !== $this->context) {
            throw new \LogicException('Constructor and action must share the claimed context.');
        }
        if ($this->fail) {
            throw new \RuntimeException('Temporary failure');
        }

        return $context->whileCurrent(fn () => WorkflowRecord::whereKey($context->workflowId)->update(['metadata' => json_encode(['written' => true])]));
    }

    public function onException(Throwable $exception, Context $context): Decision
    {
        return $context->canRetry() ? Decision::retry() : Decision::complete(false);
    }
}
