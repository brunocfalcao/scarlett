<?php

declare(strict_types=1);

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
