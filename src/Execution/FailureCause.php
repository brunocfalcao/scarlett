<?php

declare(strict_types=1);

/**
 * Names why a step reached terminal failure, independently of failure-hook retries.
 *
 * For example, AttemptLost records a worker lease expiring, while ResultPersistence
 * means the returned value could not be saved. The durable failure snapshot keeps
 * this original cause even if reporting or cleanup code subsequently fails too.
 */

namespace Scarlett\Execution;

/**
 * The origin of a terminal step outcome, distinct from later failure-hook errors.
 */
enum FailureCause: string
{
    case Decided = 'decided';
    case BudgetExhausted = 'budget_exhausted';
    case AttemptLost = 'attempt_lost';
    case HandlerCrashed = 'handler_crashed';
    case InputResolution = 'input_resolution';
    case ChildFailed = 'child_failed';
    case ChildResultMissing = 'child_result_missing';
    case ResultPersistence = 'result_persistence';
    case CompletionRejected = 'completion_rejected';
}
