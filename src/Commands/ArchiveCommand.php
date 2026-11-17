<?php

declare(strict_types=1);

/**
 * Marks old finished workflows as archived without moving or deleting their rows.
 *
 * The caller explicitly chooses an age; RetentionCommand validates it and computes
 * one cutoff. History then locks and rechecks each candidate, preserving live work
 * and existing archive timestamps. This command does not impose a default retention age.
 */

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
