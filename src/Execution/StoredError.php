<?php

declare(strict_types=1);

/**
 * Chooses the text Scarlett stores when a write or a failure hook fails.
 *
 * A database error's message quotes its SQL with the bindings, which can hold a step's
 * private inputs, result or the content a hook was writing, and the database's own text
 * can quote row values. Records keep only the error's class and SQLSTATE; the full error
 * goes to the application log. Other errors keep their message.
 */

namespace Scarlett\Execution;

use PDOException;
use Throwable;

final class StoredError
{
    public static function describe(Throwable $error): string
    {
        if (! $error instanceof PDOException) {
            return $error->getMessage();
        }
        report($error);
        $state = (string) ($error->errorInfo[0] ?? $error->getCode());

        return $error::class.(in_array($state, ['', '0'], true) ? '' : " [SQLSTATE {$state}]").' (details in the application log)';
    }
}
