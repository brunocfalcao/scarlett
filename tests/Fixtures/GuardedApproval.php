<?php

declare(strict_types=1);

/**
 * A custom state transition with a controllable permission check.
 * Tests can register a valid transition but deny it at completion time, proving the
 * engine reports that rejection on the owning step rather than its last child.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Models\StepRecord;
use Spatie\ModelStates\Transition;

class GuardedApproval extends Transition
{
    public static bool $allowed = false;

    public function __construct(private StepRecord $step) {}

    public function canTransition(): bool
    {
        return self::$allowed;
    }

    public function handle(): StepRecord
    {
        $this->step->state = Approved::class;
        $this->step->save();

        return $this->step;
    }
}
