<?php

declare(strict_types=1);

/**
 * Creates observable lock contention between independent PHP processes.
 *
 * The parent locks the contested row, starts children, waits for readiness, and then
 * confirms they are contending before releasing its lock. Readiness alone would
 * only prove process startup, not a race. Each worker returns a machine-readable
 * result and timings. Finally stops remaining children, rolls back any parent
 * transaction, and removes only this race's temporary barrier directory.
 */

namespace Scarlett\Tests\Collision;

use Illuminate\Support\Facades\DB;
use Scarlett\Models\WorkflowRecord;
use Symfony\Component\Process\Process;

final class Workers
{
    /** Hold the workflow lock until both contenders are trying to execute. */
    public static function race(string $workflow, array $operations, string $lockTable = 'scarlett_workflows'): array
    {
        $directory = sys_get_temp_dir().'/scarlett-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $processes = [];
        DB::beginTransaction();
        try {
            if ($lockTable === 'scarlett_control') {
                DB::table('scarlett_control')->where('id', 'admission')->lockForUpdate()->first();
            } elseif (in_array($lockTable, ['scarlett_subject_slots', 'scarlett_throttles'], true)) {
                DB::table($lockTable)->where('id', $workflow)->lockForUpdate()->sole();
            } else {
                WorkflowRecord::whereKey($workflow)->lockForUpdate()->firstOrFail();
            }
            foreach ($operations as $index => $operation) {
                $arguments = $operation + ['directory' => $directory, 'worker' => (string) $index];
                $process = new Process([PHP_BINARY, __DIR__.'/worker.php', json_encode($arguments, JSON_THROW_ON_ERROR)]);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            /**
             * All contenders must have booted before the parent opens the release barrier.
             * Server lock views then prove they actually reached the held row; SQLite's
             * pre-BEGIN markers and acquisition times provide its writer-serialization proof.
             */
            self::until(fn (): bool => count(glob($directory.'/*.ready')) === count($operations));
            touch($directory.'/release');
            if (Environment::driver() === 'sqlite') {
                // The parent owns SQLite's writer lock. Observe both workers immediately before BEGIN IMMEDIATE.
                self::until(fn (): bool => count(glob($directory.'/*.contending')) === count($operations));
            }
            // Both database sessions must be waiting on the lock; readiness alone is not collision proof.
            self::until(function () use ($operations, $processes, $lockTable): bool {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        throw new \RuntimeException('Worker exited before lock contention: '.$process->getErrorOutput().$process->getOutput());
                    }
                }

                return Environment::driver() === 'sqlite' || Environment::waitingWorkers($lockTable) >= count($operations);
            });
            $releasedAt = microtime(true);
            DB::commit();

            return array_map(function (Process $process) use ($releasedAt): array {
                $process->wait();
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException($process->getErrorOutput().$process->getOutput());
                }

                try {
                    $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                    if (Environment::driver() === 'sqlite' && ($result['acquired_at'] === null || $result['acquired_at'] < $releasedAt)) {
                        throw new \RuntimeException('SQLite worker acquired its transaction before the parent released the writer lock.');
                    }

                    return $result;
                } catch (\JsonException $exception) {
                    throw new \RuntimeException('Invalid worker output: '.$process->getOutput(), previous: $exception);
                }
            }, $processes);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private static function until(callable $condition): void
    {
        $deadline = microtime(true) + 10;
        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Required concurrent overlap was not observed.');
            }
            usleep(10000);
        }
    }
}
