<?php

declare(strict_types=1);

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
            } elseif ($lockTable === 'scarlett_subject_slots') {
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
            self::until(fn (): bool => count(glob($directory.'/*.ready')) === count($operations));
            touch($directory.'/release');
            // Both database sessions must be waiting on the lock; readiness alone is not collision proof.
            self::until(function () use ($operations, $processes, $lockTable): bool {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        throw new \RuntimeException('Worker exited before lock contention: '.$process->getErrorOutput().$process->getOutput());
                    }
                }
                $row = DB::selectOne("SELECT COUNT(DISTINCT thread_id) AS waiting FROM performance_schema.data_locks WHERE object_schema = ? AND object_name = ? AND lock_status = 'WAITING'", [Environment::DATABASE, $lockTable]);

                return (int) $row->waiting >= count($operations);
            });
            DB::commit();

            return array_map(function (Process $process): array {
                $process->wait();
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException($process->getErrorOutput().$process->getOutput());
                }

                try {
                    return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
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
