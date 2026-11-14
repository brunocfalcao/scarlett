<?php

declare(strict_types=1);

namespace Scarlett\Commands;

use Illuminate\Support\Carbon;
use Scarlett\History;

/**
 * Marks eligible finished workflows in place using a consumer-selected retention age.
 */
final class ArchiveCommand extends RetentionCommand
{
    protected $signature = 'scarlett:archive
        {--minutes= : Archive workflows finished more than this many minutes ago}
        {--days= : Archive workflows finished more than this many days ago}
        {--batch= : Maximum workflows fetched per batch}';

    protected $description = 'Archive old settled workflows without changing existing archive timestamps';

    protected function retain(History $history, Carbon $cutoff, int $batchSize): string
    {
        return 'Archived '.$history->archiveBefore($cutoff, $batchSize).' workflow(s).';
    }
}
