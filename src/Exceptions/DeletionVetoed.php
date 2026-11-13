<?php

declare(strict_types=1);

namespace Scarlett\Exceptions;

/** Internal rollback signal; caught outside the subject deletion transaction. */
final class DeletionVetoed extends \RuntimeException {}
