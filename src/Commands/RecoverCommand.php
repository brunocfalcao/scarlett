<?php

declare(strict_types=1);

namespace Scarlett\Commands;

use Illuminate\Console\Command;
use Scarlett\Recovery;

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
