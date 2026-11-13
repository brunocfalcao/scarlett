<?php

declare(strict_types=1);

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
