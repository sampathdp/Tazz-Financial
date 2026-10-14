<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * Password policy for accounts created or changed through the admin screen. Follows current NIST
 * guidance rather than composition rules: a real minimum length, and refuse the passwords people
 * actually pick (common ones, the account's own name/email, repeated or sequential characters).
 * Login deliberately keeps the old lenient check so existing accounts can still sign in.
 */
class StrongPassword implements Rule
{
    public const MIN_LENGTH = 10;

    private const COMMON = [
        'password', 'password1', 'password123', 'passw0rd', 'qwerty123', 'qwertyuiop', 'letmein123', 'welcome123',
        'admin12345', 'administrator', 'iloveyou12', '1234567890', '0123456789', 'abc1234567', 'changeme123',
    ];

    private string $reason = '';

    public function __construct(private ?string $email = null, private ?string $name = null)
    {
    }

    public function passes($attribute, $value): bool
    {
        $v = (string) $value;
        $lower = strtolower($v);

        if (mb_strlen($v) < self::MIN_LENGTH) {
            $this->reason = 'The password must be at least ' . self::MIN_LENGTH . ' characters.';
        } elseif (in_array($lower, self::COMMON, true) || preg_match('/^(.)\1+$/', $v)) {
            $this->reason = 'That password is too common. Choose something less guessable.';
        } elseif (preg_match('/^(?:0123456789|1234567890|abcdefghij|qwertyuiop)/', $lower) || $this->sequential($lower)) {
            $this->reason = 'The password must not be a simple sequence.';
        } elseif ($this->email && str_contains($lower, strtolower(strstr($this->email, '@', true) ?: $this->email)) && strlen(strstr($this->email, '@', true)) >= 4) {
            $this->reason = 'The password must not contain the account email.';
        } elseif ($this->name && strlen(trim($this->name)) >= 4 && str_contains($lower, strtolower(trim($this->name)))) {
            $this->reason = 'The password must not contain the account name.';
        }

        return $this->reason === '';
    }

    /** True for a run like "abcdefghij" / "1234567890" anywhere in the password (8+ in a row). */
    private function sequential(string $s): bool
    {
        $run = 1;
        for ($i = 1, $n = strlen($s); $i < $n; $i++) {
            $run = (ord($s[$i]) - ord($s[$i - 1]) === 1) ? $run + 1 : 1;
            if ($run >= 8) {
                return true;
            }
        }

        return false;
    }

    public function message(): string
    {
        return $this->reason ?: 'The password is not acceptable.';
    }
}
