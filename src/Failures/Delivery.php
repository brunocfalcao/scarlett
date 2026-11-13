<?php

declare(strict_types=1);

namespace Scarlett\Failures;

use Illuminate\Support\Facades\Bus;
use Scarlett\Jobs\ExecuteFailure;
use Scarlett\Models\FailureRecord;
use Throwable;

final class Delivery
{
    public function flush(string $workflowId): void
    {
        try {
            $pending = FailureRecord::where('workflow_id', $workflowId)->where('status', Status::Queued)->whereNull('sent_at')->with('step')->get();
            foreach ($pending as $failure) {
                $claimed = FailureRecord::whereKey($failure->id)->where('attempt', $failure->attempt)->where('status', Status::Queued)->whereNull('sent_at')
                    ->where(fn ($query) => $query->whereNull('publishing_until')->orWhere('publishing_until', '<=', now()))
                    ->update(['publishing_until' => now()->addSeconds((int) config('scarlett.delivery_retry_seconds'))]);
                if ($claimed === 0) {
                    continue;
                }
                try {
                    Bus::dispatch((new ExecuteFailure($failure->id, $failure->attempt, $failure->step->executionOptions()['worker_timeout']))
                        ->onConnection($failure->step->connection)->onQueue($failure->step->queue)->delay($failure->available_at));
                    FailureRecord::whereKey($failure->id)->where('attempt', $failure->attempt)->update(['sent_at' => now(), 'publishing_until' => null]);
                } catch (Throwable $exception) {
                    FailureRecord::whereKey($failure->id)->where('attempt', $failure->attempt)->update(['publishing_until' => null]);
                    report($exception);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
