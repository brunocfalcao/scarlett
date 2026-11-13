<?php

declare(strict_types=1);

namespace Scarlett\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class SourceRecord extends Model
{
    protected $table = 'source_records';

    public $timestamps = false;

    protected $fillable = ['name'];
}
