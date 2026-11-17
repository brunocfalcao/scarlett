<?php

declare(strict_types=1);

/**
 * A small application-owned Eloquent fixture used by live model-reference tests.
 * It has no timestamps and only a name attribute, keeping restoration behavior
 * separate from Scarlett's own workflow and step persistence.
 */

namespace Scarlett\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class SourceRecord extends Model
{
    protected $table = 'source_records';

    public $timestamps = false;

    protected $fillable = ['name'];
}
