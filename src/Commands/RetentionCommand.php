<?php

declare(strict_types=1);

namespace Scarlett\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Scarlett\History;
use ValueError;

abstract class RetentionCommand extends Command
{
    final public function handle(History $history): int
    {
        try {
            $cutoff = $this->cutoff();
            $batchSize = filter_var($this->option('batch') ?? History::DEFAULT_BATCH_SIZE, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($batchSize === false) {
                throw new InvalidArgumentException('--batch must be a positive integer.');
            }
        } catch (InvalidArgumentException|ValueError $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        $this->info($this->retain($history, $cutoff, $batchSize));

        return self::SUCCESS;
    }

    private function cutoff(): Carbon
    {
        $minutes = $this->option('minutes');
        $days = $this->option('days');
        if (($minutes === null) === ($days === null)) {
            throw new InvalidArgumentException('Provide exactly one age: --minutes or --days.');
        }
        $age = filter_var($minutes ?? $days, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($age === false) {
            throw new InvalidArgumentException('Retention age must be a non-negative integer.');
        }
        $now = now();
        $cutoff = $minutes !== null ? $now->copy()->subMinutes($age) : $now->copy()->subDays($age);
        if ($cutoff->year < 1 || $cutoff->greaterThan($now)) {
            throw new InvalidArgumentException('Retention age is outside the supported date range.');
        }

        return $cutoff;
    }

    abstract protected function retain(History $history, Carbon $cutoff, int $batchSize): string;
}
