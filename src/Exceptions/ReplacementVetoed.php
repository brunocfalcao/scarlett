<?php

declare(strict_types=1);

namespace Scarlett\Exceptions;

/** Internal transaction rollback signal; callers receive null. */
final class ReplacementVetoed extends \RuntimeException {}
