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
 * Placeholders: {BRANCH} branch code, {CODE} loan type / funding product
 * code (whichever the module uses), {TYPE} caller-supplied short code (e.g.
 * payment method), {YEAR} / {YEAR:n} year, last n digits (default 4),
 * {MONTH} / {MONTH:n} current calendar month, zero-padded to n digits
 * (default 2) — display only, it does NOT affect the sequence counter below,
 * which still resets by year, {SEQ} / {SEQ:n} sequence zero-padded to n
 * digits (default 6).
 */
class NumberGenerator
{
    private const DEFAULT_FORMATS = [
        'loan' => '{BRANCH}-{CODE}-{YEAR:2}-{SEQ:6}',
        'customer' => 'CUS-{SEQ:6}',
        // {TYPE} is the payment method's short code (CA/BT/CQ) — see
        // LoanPaymentController::TYPE_CODES. One shared sequence per
        // branch+year across all methods, same as a physical receipt book.
        'payment' => '{BRANCH}-{TYPE}-{YEAR:2}-{SEQ:5}',
        'fd_deposit' => '{BRANCH}-DEP-{YEAR:2}-{MONTH:2}-{SEQ:6}',
        // {CODE} (the funding product's own code, e.g. "TT") already
        // distinguishes this as a fixed deposit — a literal "FD" segment was
        // redundant, so it's dropped in favor of the calendar month.
        'fixed_deposit' => '{BRANCH}-{CODE}-{YEAR:2}-{MONTH:2}-{SEQ:6}',
        'fd_withdrawal' => '{BRANCH}-FDV-{YEAR:2}-{SEQ:5}',
    ];

    // Modules whose {CODE} placeholder is keyed off a funding product (own
    // number_sequences column) rather than a loan type — see the
    // 2026_07_28_102007 migration for why they can't share loan_type_id.
    private const PRODUCT_SCOPED_MODULES = ['fixed_deposit'];

    public static function next(string $module, array $context = [], ?string $format = null): string
    {
        $format ??= self::DEFAULT_FORMATS[$module] ?? throw new \InvalidArgumentException("No default reference format for module [{$module}].");

        $usesBranch = str_contains($format, '{BRANCH}');
        $usesCode = str_contains($format, '{CODE}');
        $isProductScoped = in_array($module, self::PRODUCT_SCOPED_MODULES, true);
        $usesLoanType = $usesCode && !$isProductScoped;
        $usesProduct = $usesCode && $isProductScoped;
        $usesYear = (bool) preg_match('/\{YEAR(?::\d+)?\}/', $format);

        $branchId = $usesBranch ? (int) ($context['branch_id'] ?? 0) : 0;
        $loanTypeId = $usesLoanType ? (int) ($context['loan_type_id'] ?? 0) : 0;
        $productId = $usesProduct ? (int) ($context['funding_product_id'] ?? 0) : 0;
        $year = $usesYear ? (int) now()->format('Y') : 0;

        $key = ['module' => $module, 'branch_id' => $branchId, 'loan_type_id' => $loanTypeId, 'funding_product_id' => $productId, 'year' => $year];

        // Atomically create the counter row if this is the first number ever
        // issued for this key, then lock it so concurrent requests serialize
        // on the increment below instead of computing the same "next" value.
        DB::table('number_sequences')->insertOrIgnore([$key + ['last_sequence' => 0, 'created_at' => now(), 'updated_at' => now()]]);
        $seqRow = NumberSequence::where($key)->lockForUpdate()->first();
        $sequence = $seqRow->last_sequence + 1;
        $seqRow->update(['last_sequence' => $sequence]);

        $number = strtr($format, [
            '{BRANCH}' => $context['branch_code'] ?? '',
            '{CODE}' => $context['loan_type_code'] ?? $context['funding_product_code'] ?? '',
            '{TYPE}' => $context['type_code'] ?? '',
        ]);
        $number = preg_replace_callback(
            '/\{YEAR(?::(\d+))?\}/',
            fn ($m) => isset($m[1]) ? substr(now()->format('Y'), -(int) $m[1]) : now()->format('Y'),
            $number
        );
        $number = preg_replace_callback(
            '/\{MONTH(?::(\d+))?\}/',
            fn ($m) => str_pad(now()->format('n'), isset($m[1]) ? (int) $m[1] : 2, '0', STR_PAD_LEFT),
            $number
        );
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
            'funding_product_id' => $productId ?: null,
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
