<?php

declare(strict_types=1);

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
