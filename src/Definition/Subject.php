<?php

declare(strict_types=1);

/**
 * Identifies a business entity whose workflows the application owns.
 *
 * The pair distinguishes, for example, order "42" from invoice "42". Exact string
 * comparison matters: a database collation may treat "Order" and "order" as equal,
 * but ownership checks must not merge distinct subjects. This object does not load
 * or require any particular application model.
 */

namespace Scarlett\Definition;

use InvalidArgumentException;

/**
 * An application-owned identity with exact comparison, independent of SQL collation.
 */
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
