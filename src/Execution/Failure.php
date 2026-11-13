<?php

declare(strict_types=1);

namespace Scarlett\Execution;

use InvalidArgumentException;
use Scarlett\Inputs\PayloadCodec;
use Scarlett\Models\FailureRecord;

final readonly class Failure
{
    public function __construct(
        public string $id,
        public FailureCause $cause,
        public ?string $reason,
        public string $stepKey,
        public string $stepClass,
        public array $bindings,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $metadata,
        public ?string $preparationError = null,
    ) {}

    public static function fromRecord(FailureRecord $record, ?string $preparationError = null): self
    {
        $snapshot = $record->snapshot;

        return new self($record->id, $record->cause, $record->reason, $snapshot['key'], $snapshot['class'],
            $snapshot['inputs'], $snapshot['subject_type'], $snapshot['subject_id'], $snapshot['metadata'], $preparationError);
    }

    /** Decode only the requested literal; unrelated broken bindings are never resolved. */
    public function literalInput(string $name): mixed
    {
        $binding = $this->bindings[$name] ?? null;
        if ($binding === null || $binding['kind'] !== 'literal') {
            throw new InvalidArgumentException("Failure input $name is not a stored literal.");
        }

        return app(PayloadCodec::class)->decode($binding['payload']);
    }
}
