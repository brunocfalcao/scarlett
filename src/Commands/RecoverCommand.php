<?php

declare(strict_types=1);

/**
 * Exposes interrupted-work repair to Artisan and the application's scheduler.
 *
 * An optional workflow ID narrows the scan. Recovery handles overdue deliveries and
 * expired action or failure-hook leases; normal step completion already progresses
 * work immediately. The displayed count is repaired executions, not whole workflows.
 */

namespace Scarlett\Commands;

use Illuminate\Console\Command;
use Scarlett\Recovery;

/**
 * Scheduler or manual entry point for repair, not the normal progression loop.
 */
final class RecoverCommand extends Command
{
    protected $signature = 'scarlett:recover {workflow? : Limit recovery to one workflow}';

    protected $description = 'Recover expired executions and replay lost deliveries';

    public function handle(Recovery $recovery): int
    {
        $this->info('Recovered '.$recovery->resume($this->argument('workflow')).' execution(s).');

        return self::SUCCESS;
    }
}
