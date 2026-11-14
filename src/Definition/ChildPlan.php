<?php

declare(strict_types=1);

namespace Scarlett\Definition;

/** A closed graph whose keys and references are local to its running owner. */
final readonly class ChildPlan
{
    /** @param list<Node> $nodes */
    public function __construct(public array $nodes, public ?string $resultFrom = null) {}
}
