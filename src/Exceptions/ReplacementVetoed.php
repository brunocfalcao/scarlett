<?php

declare(strict_types=1);

/**
 * Signals that a prepared workflow replacement should not be committed.
 *
 * WorkflowSlots throws this after a null or false preparation result so cancellation
 * of the old workflow and callback writes roll back together. It catches the signal
 * outside the transaction and presents the veto as a null public result.
 */

namespace Scarlett\Exceptions;

/** Internal transaction rollback signal; callers receive null. */
final class ReplacementVetoed extends \RuntimeException {}
