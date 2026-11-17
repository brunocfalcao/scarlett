<?php

declare(strict_types=1);

/**
 * The complete set of children a running step wants to add in one handoff.
 *
 * Node keys and their references are local to this plan. RuntimeChildren validates
 * and prefixes them before saving. resultFrom optionally selects a direct child's
 * saved value as the parent's result; without it, the decision's own result is used.
 * An empty plan is allowed and still leads to parent verification without replay.
 */

namespace Scarlett\Definition;

/** A closed graph whose keys and references are local to its running owner. */
final readonly class ChildPlan
{
    /** @param list<Node> $nodes */
    public function __construct(public array $nodes, public ?string $resultFrom = null) {}
}
