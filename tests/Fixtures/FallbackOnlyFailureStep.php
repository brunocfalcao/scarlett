<?php

declare(strict_types=1);

/**
 * Overrides only the static terminal hook. The base instance hook delegates to it
 * when construction succeeds, and the failure runner calls it directly when it does
 * not. Saved call details distinguish these paths without requiring separate actions.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Execution\Context;
use Scarlett\Execution\Decision;
use Scarlett\Execution\Failure;
use Scarlett\Models\WorkflowRecord;
use Scarlett\Step;

class FallbackOnlyFailureStep extends Step
{
    public static array $calls = [];

    public function __construct(public string $label) {}

    public function before(Context $context): ?Decision
    {
        return Decision::fail('Fallback delegation.');
    }

    public function handle(): void {}

    public static function onFailureWithoutInstance(Context $context, Failure $failure): void
    {
        self::$calls[] = [$failure->literalInput('label'), $failure->preparationError];
        $context->whileCurrent(function () use ($context, $failure): void {
            $flow = WorkflowRecord::findOrFail($context->workflowId);
            $flow->update(['metadata' => ['delegated' => $failure->literalInput('label')]]);
        });
    }
}
