<?php

declare(strict_types=1);

namespace Scarlett\Definition;

use InvalidArgumentException;

final readonly class Subject
{
    public function __construct(public string $subjectType, public string $subjectId)
    {
        foreach ([$subjectType, $subjectId] as $value) {
            if ($value === '' || ! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('A subject requires non-empty UTF-8 identities.');
            }
        }
    }

    public function matches(?string $subjectType, ?string $subjectId): bool
    {
        return $this->subjectType === $subjectType && $this->subjectId === $subjectId;
    }
}
