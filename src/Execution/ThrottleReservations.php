<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Scarlett\Models\StepRecord;
use Scarlett\Policies\RateLimit;
use UnexpectedValueException;

/**
 * Reserves quota under workflow -> step -> throttle locks, never around an API call.
 * A durable receipt makes repeated checks of the same attempt cost one admission.
 */
final class ThrottleReservations
{
    public const MILLISECONDS_PER_SECOND = 1000;

    public const DEFAULT_BATCH_SIZE = 100;

    public function delay(RateLimit $policy, Context $context): ?int
    {
        if ($context->failureId !== null) {
            throw new InvalidArgumentException('RateLimit controls step actions, not terminal failure handlers.');
        }
        $delay = null;
        $current = $context->whileCurrent(function () use ($policy, $context, &$delay): void {
            // Reset callback-local output because the owning transaction may retry.
            $delay = null;
            $step = StepRecord::whereKey($context->stepId)->lockForUpdate()->sole();
            if ($step->action_completed) {
                throw new LogicException('An already persisted action cannot reserve another admission.');
            }
            $id = $policy->id();
            $receipt = $step->throttle_reservations ?? [];
            $limits = ($receipt['attempt'] ?? null) === $context->attempt ? ($receipt['limits'] ?? []) : [];
            if (in_array($id, $limits, true)) {
                return;
            }

            // Duplicate-key update serializes first creation as well as existing quotas.
            DB::table('scarlett_throttles')->upsert([
                'id' => $id, 'grants' => '[]', 'expires_at' => 0,
                'max_requests' => $policy->maxRequests, 'per_seconds' => $policy->perSeconds,
            ], ['id'], ['id']);
            $bucket = DB::table('scarlett_throttles')->where('id', $id)->lockForUpdate()->sole();
            // Waiting for another workflow's quota lock can outlast this attempt's lease.
            if (! $step->lease_until?->isFuture()) {
                throw new LogicException('The attempt lost ownership while waiting for its throttle.');
            }
            $now = now()->getTimestampMs();
            if ((int) $bucket->expires_at > $now && ((int) $bucket->max_requests !== $policy->maxRequests || (int) $bucket->per_seconds !== $policy->perSeconds)) {
                throw new LogicException('An active throttle has different limits. Drain its window before changing the named policy.');
            }
            $window = $policy->perSeconds * self::MILLISECONDS_PER_SECOND;
            $grants = json_decode($bucket->grants, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($grants) || ! array_is_list($grants)
                || array_any($grants, fn ($grant): bool => ! is_int($grant))) {
                throw new UnexpectedValueException('Invalid persisted throttle reservations.');
            }
            $grants = (int) $bucket->expires_at <= $now ? []
                : array_values(array_filter($grants, fn (int $grant): bool => $grant > $now - $window));
            sort($grants, SORT_NUMERIC);
            if (count($grants) >= $policy->maxRequests) {
                $release = $grants[0] + $window;
                $delay = (int) ceil(($release - $now) / self::MILLISECONDS_PER_SECOND);

                return;
            }
            $grants[] = $now;
            DB::table('scarlett_throttles')->where('id', $id)->update([
                'grants' => json_encode($grants, JSON_THROW_ON_ERROR),
                'expires_at' => max($grants) + $window,
                'max_requests' => $policy->maxRequests, 'per_seconds' => $policy->perSeconds,
            ]);
            $limits[] = $id;
            $step->update(['throttle_reservations' => ['attempt' => $context->attempt, 'limits' => $limits]]);
        });
        if (! $current) {
            throw new LogicException('Only a current running attempt can reserve throttle capacity.');
        }

        return $delay;
    }

    /** Remove expired identities in bounded batches; never reset a still-active quota. */
    public function pruneExpired(int $batchSize = self::DEFAULT_BATCH_SIZE): int
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Throttle pruning batch size must be positive.');
        }
        $cutoff = now()->getTimestampMs();
        $removed = 0;
        $candidates = DB::table('scarlett_throttles')->where('expires_at', '<=', $cutoff)->select('id');
        foreach ($candidates->lazyById($batchSize) as $candidate) {
            // Recheck in the DELETE: a worker may have renewed the quota since selection.
            $removed += DB::table('scarlett_throttles')->where('id', $candidate->id)->where('expires_at', '<=', $cutoff)->delete();
        }

        return $removed;
    }
}
