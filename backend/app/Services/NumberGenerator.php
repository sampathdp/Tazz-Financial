<?php

namespace App\Services;

use App\Models\GeneratedNumber;
use App\Models\NumberSequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Generates and logs reference numbers (loan codes, customer codes, payment
 * codes, ...) from a placeholder template, backed by a per-key sequence
 * counter. Must be called from inside the caller's own DB::transaction — it
 * doesn't open one itself, since it always runs alongside the parent record's
 * creation (e.g. LoanController::store()'s transaction).
 *
 * Placeholders: {BRANCH} branch code, {CODE} loan type code, {YEAR} 4-digit
 * year, {SEQ} / {SEQ:n} sequence zero-padded to n digits (default 6).
 */
class NumberGenerator
{
    private const DEFAULT_FORMATS = [
        'loan' => '{BRANCH}-{CODE}-{YEAR}-{SEQ:6}',
        'customer' => 'CUS-{SEQ:6}',
        'payment' => '{BRANCH}-PMT-{YEAR}-{SEQ:6}',
    ];

    public static function next(string $module, array $context = [], ?string $format = null): string
    {
        $format ??= self::DEFAULT_FORMATS[$module] ?? throw new \InvalidArgumentException("No default reference format for module [{$module}].");

        $usesBranch = str_contains($format, '{BRANCH}');
        $usesLoanType = str_contains($format, '{CODE}');
        $usesYear = str_contains($format, '{YEAR}');

        $branchId = $usesBranch ? (int) ($context['branch_id'] ?? 0) : 0;
        $loanTypeId = $usesLoanType ? (int) ($context['loan_type_id'] ?? 0) : 0;
        $year = $usesYear ? (int) now()->format('Y') : 0;

        $key = ['module' => $module, 'branch_id' => $branchId, 'loan_type_id' => $loanTypeId, 'year' => $year];

        // Atomically create the counter row if this is the first number ever
        // issued for this key, then lock it so concurrent requests serialize
        // on the increment below instead of computing the same "next" value.
        DB::table('number_sequences')->insertOrIgnore([$key + ['last_sequence' => 0, 'created_at' => now(), 'updated_at' => now()]]);
        $seqRow = NumberSequence::where($key)->lockForUpdate()->first();
        $sequence = $seqRow->last_sequence + 1;
        $seqRow->update(['last_sequence' => $sequence]);

        $number = strtr($format, [
            '{BRANCH}' => $context['branch_code'] ?? '',
            '{CODE}' => $context['loan_type_code'] ?? '',
            '{YEAR}' => now()->format('Y'),
        ]);
        $number = preg_replace_callback(
            '/\{SEQ(?::(\d+))?\}/',
            fn ($m) => str_pad((string) $sequence, isset($m[1]) ? (int) $m[1] : 6, '0', STR_PAD_LEFT),
            $number
        );

        GeneratedNumber::create([
            'module' => $module,
            'number' => $number,
            'branch_id' => $branchId ?: null,
            'loan_type_id' => $loanTypeId ?: null,
            'year' => $year ?: null,
            'sequence' => $sequence,
            'generated_by' => $context['user_id'] ?? null,
        ]);

        return $number;
    }

    /** Links a just-generated number to the record it was created for. */
    public static function attach(string $number, Model $record): void
    {
        GeneratedNumber::where('number', $number)->latest('id')->first()?->reference()->associate($record)->save();
    }
}
