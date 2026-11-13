<?php

declare(strict_types=1);

namespace Scarlett\Definition;

final readonly class Node
{
    public function __construct(
        public string $key,
        public string $step,
        public int $stage = 0,
        public array $inputs = [],
        public ?string $parent = null,
        public ?string $rescues = null,
        public int $priority = 0,
        public ?string $queue = null,
        public ?string $connection = null,
    ) {}
}
