<?php

declare(strict_types=1);

namespace Scarlett\Commands;

use Illuminate\Console\Command;
use Scarlett\Execution\ThrottleReservations;

/** Optional housekeeping for expired quota keys; active limits are never reset. */
final class PruneThrottlesCommand extends Command
{
    protected $signature = 'scarlett:prune-throttles {--batch= : Maximum identities fetched per batch}';

    protected $description = 'Remove expired Scarlett throttle identities without affecting active quotas';

    public function handle(ThrottleReservations $reservations): int
    {
        $batch = $this->option('batch') ?? ThrottleReservations::DEFAULT_BATCH_SIZE;
        $batch = filter_var($batch, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($batch === false) {
            $this->error('The batch size must be a positive integer.');

            return self::INVALID;
        }
        $this->info('Removed '.$reservations->pruneExpired($batch).' expired throttle identities.');

        return self::SUCCESS;
    }
}
