<?php

declare(strict_types=1);

/**
 * Accepts a restored SourceRecord and returns an explicit live model reference.
 * Tests exercise both input restoration and result restoration without asking the
 * codec to serialize an arbitrary Eloquent model instance.
 */

namespace Scarlett\Tests\Fixtures;

use Scarlett\Inputs\ModelReference;
use Scarlett\Step;

class ModelEchoStep extends Step
{
    public function handle(SourceRecord $record): ModelReference
    {
        return new ModelReference('source_record', $record->id);
    }
}
