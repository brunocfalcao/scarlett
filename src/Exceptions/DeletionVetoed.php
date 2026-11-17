<?php

declare(strict_types=1);

/**
 * Signals that the application's subject-deletion callback declined the operation.
 *
 * Throwing inside the transaction rolls back all changes, including callback writes.
 * SubjectDeletion catches it outside the transaction and returns false. Returning
 * false inside the transaction instead would commit changes already made there.
 */

namespace Scarlett\Exceptions;

/** Internal rollback signal; caught outside the subject deletion transaction. */
final class DeletionVetoed extends \RuntimeException {}
