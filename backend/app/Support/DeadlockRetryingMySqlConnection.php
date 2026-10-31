<?php

namespace App\Support;

use Closure;
use Illuminate\Database\MySqlConnection;

/**
 * MySQL connection whose transactions retry on a deadlock.
 *
 * Money paths take several row locks (a facility, a cash/bank account, a number sequence, the ledger), and two
 * requests that reach them in different orders can deadlock; MySQL then kills one with error 1213. Laravel's
 * transaction() only retries when it is given an attempts count, and almost every call site in this app uses the
 * default of 1, so a deadlock surfaced as a 500 even though nothing had been saved and a retry would succeed.
 * Defaulting to 3 attempts here fixes every call site at once. Laravel retries only the OUTERMOST transaction and
 * only for deadlock/lock errors, never for validation or posting errors, and the whole closure re-runs from a
 * clean rollback, so nothing is saved twice.
 */
class DeadlockRetryingMySqlConnection extends MySqlConnection
{
    public const DEFAULT_ATTEMPTS = 3;

    public function transaction(Closure $callback, $attempts = 1)
    {
        return parent::transaction($callback, max((int) $attempts, self::DEFAULT_ATTEMPTS));
    }
}
