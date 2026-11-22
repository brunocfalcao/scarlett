<?php

declare(strict_types=1);

/**
 * An abstract transition class. Spatie instantiates a transition class when a state change
 * uses it, so registering the abstract form must fail at configuration time.
 */

namespace Scarlett\Tests\Fixtures;

use Spatie\ModelStates\Transition;

abstract class CustomAlertTransition extends Transition {}
