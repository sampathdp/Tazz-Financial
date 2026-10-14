<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * A currency amount: at most two decimals and small enough for the decimal(15,2) columns. Without
 * it a typo like 1e20 reaches the database as a range error (a generic 500) and 10.005 is
 * silently rounded. Combine with 'numeric' and any min/gt rule.
 */
class Money implements Rule
{
    public const MAX = 9999999999999.99;

    public function passes($attribute, $value): bool
    {
        if (!is_numeric($value)) {
            return true; // the 'numeric' rule reports that
        }
        $s = is_string($value) ? trim($value) : (string) $value;

        return (bool) preg_match('/^-?\d{1,13}(\.\d{1,2})?$/', $s) && abs((float) $s) <= self::MAX;
    }

    public function message(): string
    {
        return 'The :attribute must be a valid amount with at most 2 decimal places and no more than 9,999,999,999,999.99.';
    }
}
