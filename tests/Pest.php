<?php

declare(strict_types=1);
use Scarlett\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
require_once __DIR__.'/Support/functions.php';
pest()->extend(Scarlett\Tests\Collision\TestCase::class)->in('Collision');
