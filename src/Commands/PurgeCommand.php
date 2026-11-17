<?php

declare(strict_types=1);

/**
 * Permanently removes archived workflow history older than a caller-selected age.
 *
 * The age applies to archived_at, not creation time. RetentionCommand handles input
 * validation; History rechecks settlement under each workflow's lock and removes
 * dependent records in order. Unfinished actions and failure hooks remain protected.
 */

namespace Scarlett\Commands;

use Illuminate\Support\Carbon;
use Scarlett\History;

/**
 * Permanently removes eligible archived graphs while preserving unfinished work.
 */
final class PurgeCommand extends RetentionCommand
{
    protected $signature = 'scarlett:purge
        {--minutes= : Purge settled workflows archived more than this many minutes ago}
        {--days= : Purge settled workflows archived more than this many days ago}
        {--batch= : Maximum workflows fetched per batch}';

    protected $description = 'Permanently purge old archived history while preserving live work';

    protected function retain(History $history, Carbon $cutoff, int $batchSize): string
    {
        return 'Purged '.$history->purge($cutoff, $batchSize).' workflow(s).';
    }
}
