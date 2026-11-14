<?php

declare(strict_types=1);

namespace Scarlett\Definition;

use InvalidArgumentException;

/**
 * A named current-workflow lane for an application subject.
 * The hash encodes all three values without delimiter or collation ambiguity.
 */
final readonly class SubjectSlot
{
    public function __construct(public string $subjectType, public string $subjectId, public string $name)
    {
        foreach ([$subjectType, $subjectId, $name] as $value) {
            if ($value === '' || ! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('A subject slot requires non-empty UTF-8 identities.');
            }
        }
    }

    public function id(): string
    {
        return hash('sha256', json_encode([$this->subjectType, $this->subjectId, $this->name], JSON_THROW_ON_ERROR));
    }
}
