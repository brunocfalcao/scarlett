<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Scarlett\Execution\Context;
use Scarlett\Execution\Failure;
use Scarlett\Models\WorkflowRecord;
use Scarlett\Step;

final class ConnectionAwareStep extends Step
{
    public static array $observations = [];

    public static bool $verificationPasses = true;

    public function __construct(public bool $fail = false) {}

    public function handle(Context $context): string
    {
        self::$observations[] = ['action', DB::connection()->getRawPdo() === null];
        if ($this->fail) {
            throw new \RuntimeException('Action failed.');
        }
        $context->whileCurrent(function () use ($context): void {
            WorkflowRecord::findOrFail($context->workflowId)->update(['metadata' => ['saved' => true]]);
        });

        return 'saved';
    }

    public function verify(Context $context, mixed $result): bool
    {
        self::$observations[] = ['verify', DB::connection()->getRawPdo() === null];

        return self::$verificationPasses && $result === 'saved' && WorkflowRecord::findOrFail($context->workflowId)->metadata === ['saved' => true];
    }

    public function onFailure(Context $context, Failure $failure): void
    {
        self::$observations[] = ['failure', DB::connection()->getRawPdo() === null];
        $context->whileCurrent(function () use ($context): void {
            WorkflowRecord::findOrFail($context->workflowId)->update(['metadata' => ['handled' => true]]);
        });
    }
}
