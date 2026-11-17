<?php

declare(strict_types=1);

/**
 * Names one current-workflow lane belonging to a business subject.
 *
 * The triple of subject type, subject ID, and slot name lets one subject maintain
 * independent workflows for different purposes. The database primary key is a hash
 * of that full triple. JSON preserves component boundaries, avoiding collisions
 * from ambiguous delimiter concatenation; hashing avoids SQL collation comparisons.
 */

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
        /**
         * Encode the three strings separately before hashing: ["ab", "c", "x"] must
         * not share an identity with ["a", "bc", "x"]. Hashing the exact encoded bytes
         * also keeps case and accents distinct regardless of database collation.
         */
        return hash('sha256', json_encode([$this->subjectType, $this->subjectId, $this->name], JSON_THROW_ON_ERROR));
    }
}
