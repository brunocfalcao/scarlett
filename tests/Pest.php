<?php

declare(strict_types=1);

/**
 * Assigns the correct Testbench base class to each test directory.
 *
 * Feature cases default to lightweight in-process execution; Collision cases use the
 * dedicated database and independent worker infrastructure. Load shared helpers once
 * so both suites can describe and drive the same Node and Runner behavior.
 */
use Scarlett\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
require_once __DIR__.'/Support/functions.php';
pest()->extend(Scarlett\Tests\Collision\TestCase::class)->in('Collision');
