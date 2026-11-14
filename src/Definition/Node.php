<?php

declare(strict_types=1);

namespace Scarlett\Definition;

use DateTimeInterface;

/**
 * An immutable definition, not a business step instance.
 * Stages order siblings; parent and rescue links determine their separate groups.
 */
final readonly class Node
{
    /** The published schema stores identities of at most 255 UTF-8 characters. */
    public const MAX_KEY_LENGTH = 255;

    public function __construct(
        public string $key,
        public string $step,
        public int $stage = 0,
        public array $inputs = [],
        public ?string $parent = null,
        public ?string $rescues = null,
        public ?int $priority = null,
        public ?string $queue = null,
        public ?string $connection = null,
        public ?DateTimeInterface $notBefore = null,
        public ?ExecutionOptions $execution = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $canonical = null,
    ) {}

    /** A node override replaces the class options object; unset fields fall back to package defaults. */
    public function executionOptions(): ExecutionOptions
    {
        return $this->execution ?? $this->step::executionOptions();
    }
}
