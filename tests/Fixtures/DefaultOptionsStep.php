<?php

declare(strict_types=1);

/**
 * Supplies class-level timeout, lease, action budget, and verification budget.
 * Tests compare these distinct values with node overrides and package defaults to
 * prove admission saves the intended complete execution settings.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Definition\ExecutionOptions;

class DefaultOptionsStep extends ControlledStep
{
    public static function executionOptions(): ExecutionOptions
    {
        return new ExecutionOptions(workerTimeout: 40, leaseSeconds: 60, attempts: 2, backoffSeconds: [7], verificationAttempts: 4, verificationBackoffSeconds: [11]);
    }
}
