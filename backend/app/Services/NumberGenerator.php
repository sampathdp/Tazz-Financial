<?php

namespace App\Services;

use App\Models\DocumentNumberLog;
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
 *
 * The {SEQ} counter is always ONE company-wide series per module (+ loan
 * type/product + year, where the format uses those) — never split by branch.
 * {BRANCH} is cosmetic only (which branch issued it); two branches saving a
 * loan/receipt at the same instant must land on different {SEQ} values, not
 * both "00001". Concurrent requests — even from different branches — safely
 * serialize on the same number_sequences row via lockForUpdate() below, since
 * branch is no longer part of that row's key.
 */
class NumberGenerator
{
    private const DEFAULT_FORMATS = [
        // {SEQ:6} = zero-padded (HB-SMEW-26-000005); counter is shared across loan types (see resolveKey).
        'loan' => '{BRANCH}-{CODE}-{YEAR:2}-{SEQ:6}',
        'customer' => 'CUS-{SEQ:6}',
        // {TYPE} is the payment method's short code (CA/BT/CQ) — see
        // LoanPaymentController::TYPE_CODES. One shared sequence per
        // branch+year across all methods, same as a physical receipt book.
        'payment' => '{BRANCH}-{TYPE}-{YEAR:2}-{MONTH:2}-{SEQ:5}',
        'fd_deposit' => '{BRANCH}-DEP-{YEAR:2}-{MONTH:2}-{SEQ:6}',
        // {CODE} (the funding product's own code, e.g. "TT") already
        // distinguishes this as a fixed deposit — a literal "FD" segment was
        // redundant, so it's dropped in favor of the calendar month.
        'fixed_deposit' => '{BRANCH}-{CODE}-{YEAR:2}-{MONTH:2}-{SEQ:6}',
        'fd_withdrawal' => '{BRANCH}-FDV-{YEAR:2}-{SEQ:5}',
        'fd_part_payment' => '{BRANCH}-FDP-{YEAR:2}-{SEQ:5}',
        // Monthly FD interest payout voucher (the slip that goes with the bank transfer).
        'fd_interest' => '{BRANCH}-FDI-{YEAR:2}-{SEQ:5}',
        // One company-wide series, no {BRANCH} — a journal entry isn't tied to a
        // single branch the way a receipt is (see LedgerPoster).
        'journal' => 'JV-{YEAR:2}-{SEQ:6}',
        // Branch-to-branch funding agreement; company-wide series, it spans two branches.
        'branch_funding' => 'BFA-{YEAR:2}-{SEQ:5}',
        // Finance module money-movement ledger (see FinancePostingService) —
        // one company-wide series per year, like 'journal': a finance
        // transaction spans branches (a branch-to-branch transfer) and is not
        // a branch receipt book.
        'finance_transaction' => 'FTX-{YEAR:2}-{SEQ:6}',
        // Finance request (the outbound-payment queue, see FinanceRequestService).
        // {BRANCH} is the requesting branch, cosmetic — one company-wide series.
        'finance_request' => '{BRANCH}-FR-{YEAR:2}-{SEQ:5}',
        // Staging doc for a manual journal entry awaiting approval (see
        // ManualJournalEntryController) — the real JV- code is minted by
        // LedgerPoster only once it's approved and posted.
        'manual_journal' => 'MJV-{YEAR:2}-{SEQ:5}',
        'expense' => '{BRANCH}-EXP-{YEAR:2}-{SEQ:5}',
        'payment_voucher' => '{BRANCH}-PV-{YEAR:2}-{SEQ:5}',
        // No {YEAR} — a fund is opened once and kept for years, not reissued
        // annually like a receipt/voucher book.
        'petty_cash_fund' => '{BRANCH}-PCF-{SEQ:4}',
        'petty_cash_replenishment' => '{BRANCH}-PCR-{YEAR:2}-{SEQ:5}',
        'petty_cash_voucher' => '{BRANCH}-PCV-{YEAR:2}-{SEQ:5}',
        // No {CODE} — Speed Draft Types aren't wired into the loan_type_id/
        // funding_product_id key columns (see SpeedDraftController::store()),
        // so numbering is one company-wide series per year across all types.
        'speed_draft' => '{BRANCH}-SD-{YEAR:2}-{SEQ:6}',
        // {TYPE} is the payment's intent short code (INT/CAP/SET) — see
        // SpeedDraftPaymentController::TYPE_CODES.
        'speed_draft_payment' => '{BRANCH}-SDP-{TYPE}-{YEAR:2}-{MONTH:2}-{SEQ:5}',
        // No {CODE} — same reasoning as speed_draft: pawning_type_id isn't
        // wired into the loan_type_id/funding_product_id key columns, so
        // numbering is one company-wide series per year across all schemes.
        'pawning' => '{BRANCH}-PW-{YEAR:2}-{SEQ:6}',
        'pawning_auction' => '{BRANCH}-PWA-{YEAR:2}-{SEQ:6}',
        // Company borrowings (money the company itself borrows) and their
        // repayment/interest vouchers — one company-wide series per year each.
        'borrowing' => '{BRANCH}-BRW-{YEAR:2}-{SEQ:6}',
        'borrowing_txn' => '{BRANCH}-BRWV-{YEAR:2}-{SEQ:5}',
        // Daily Savings: an account is opened once (no {YEAR}); its
        // transactions and collection sheets reset yearly; interest runs are
        // one company-wide series per year.
        'savings_account' => '{BRANCH}-SAV-{SEQ:6}',
        'savings_txn' => '{BRANCH}-SVT-{YEAR:2}-{SEQ:6}',
        'savings_collection' => '{BRANCH}-SCS-{YEAR:2}-{SEQ:5}',
        'savings_interest' => 'SIR-{YEAR:2}-{SEQ:5}',
        // No {YEAR} — an asset keeps its tag for life.
        'fixed_asset' => '{BRANCH}-FA-{SEQ:5}',
    ];

    // Modules whose {CODE} placeholder is keyed off a funding product (own
    // number_sequences column) rather than a loan type, since a funding
    // product and a loan type are different tables with different id spaces.
    private const PRODUCT_SCOPED_MODULES = ['fixed_deposit'];

    // module => [table, column] holding the issued numbers, used to detect a counter that fell behind its data.
    private const CODE_COLUMNS = [
        'loan' => ['credit_facilities', 'code'],
        'customer' => ['customers', 'code'],
        'payment' => ['facility_payments', 'code'],
        'journal' => ['journal_entries', 'code'],
        'finance_transaction' => ['finance_transactions', 'code'],
        'finance_request' => ['finance_requests', 'code'],
        'branch_funding' => ['branch_funding_agreements', 'code'],
    ];

    public static function next(string $module, array $context = [], ?string $format = null): string
    {
        $format ??= self::formatFor($module);
        $key = self::resolveKey($module, $context, $format);

        // Atomically create the counter row if this is the first number ever
        // issued for this key, then lock it so concurrent requests serialize
        // on the increment below instead of computing the same "next" value.
        // The shared loan row is seeded from the highest per-type counter so numbers carry on, not restart at 1.
        $seed = in_array($module, ['loan', 'fixed_deposit'], true)
            ? (int) NumberSequence::where('module', $module)->where('year', $key['year'])->max('last_sequence')
            : 0;
        DB::table('number_sequences')->insertOrIgnore([$key + ['last_sequence' => $seed, 'created_at' => now(), 'updated_at' => now()]]);
        $seqRow = NumberSequence::where($key)->lockForUpdate()->first();
        $sequence = $seqRow->last_sequence + 1;
        $number = self::format($format, $context, $sequence);

        // The counter can fall behind the real records (restored/seeded DB, a counter row lost or
        // rolled back while the record it numbered survived). Skip any number that is already
        // taken instead of failing the whole request on the unique index.
        if (isset(self::CODE_COLUMNS[$module])) {
            [$table, $column] = self::CODE_COLUMNS[$module];
            for ($i = 0; $i < 1000 && DB::table($table)->where($column, $number)->exists(); $i++) {
                $sequence++;
                $number = self::format($format, $context, $sequence);
            }
        }
        $seqRow->update(['last_sequence' => $sequence]);

        // source_type/source_id start null and are filled in shortly after by
        // attach(), once the record this number is for actually has an id —
        // see the document_number_log migration.
        DocumentNumberLog::create([
            'number_sequence_id' => $seqRow->id,
            'generated_number' => $number,
            'generated_by' => $context['user_id'] ?? null,
        ]);

        return $number;
    }

    /**
     * Non-mutating preview of the number `next()` would produce for this key right
     * now — for UI display before the record actually exists (e.g. showing the
     * receipt number a payment form will get, before it's submitted). Doesn't lock
     * or increment the counter and doesn't write a document_number_log row, so it's
     * only a preview: if another request calls next() for the same key first, the
     * real number issued can end up different from what was previewed here.
     */
    public static function peek(string $module, array $context = [], ?string $format = null): string
    {
        $format ??= self::formatFor($module);
        $key = self::resolveKey($module, $context, $format);

        $sequence = (int) (NumberSequence::where($key)->value('last_sequence') ?? 0) + 1;

        return self::format($format, $context, $sequence);
    }

    /** Links a just-generated number to the record it was created for. */
    public static function attach(string $number, Model $record): void
    {
        DocumentNumberLog::where('generated_number', $number)->latest('id')->first()?->update([
            'source_type' => class_basename($record),
            'source_id' => $record->getKey(),
        ]);
    }

    private static function formatFor(string $module): string
    {
        return self::DEFAULT_FORMATS[$module] ?? throw new \InvalidArgumentException("No default reference format for module [{$module}].");
    }

    /**
     * The number_sequences row key for this module/context — null (not 0)
     * for every dimension the format doesn't use, since branch_id/
     * loan_type_id/funding_product_id/year are now real nullable foreign
     * keys rather than 0-sentinel columns (see that table's migration).
     */
    private static function resolveKey(string $module, array $context, string $format): array
    {
        $usesCode = str_contains($format, '{CODE}');
        $isProductScoped = in_array($module, self::PRODUCT_SCOPED_MODULES, true);
        // Loan numbers run on one continuous series across every loan type.
        $usesLoanType = $usesCode && !$isProductScoped && $module !== 'loan';
        // Fixed deposit numbers run on one continuous series across every funding product.
        $usesProduct = $usesCode && $isProductScoped && $module !== 'fixed_deposit';
        $usesYear = (bool) preg_match('/\{YEAR(?::\d+)?\}/', $format);

        return [
            'module' => $module,
            // Reserved on number_sequences but never part of today's key —
            // {SEQ} is always one company-wide series (see class docblock).
            'branch_id' => null,
            'loan_type_id' => $usesLoanType ? ($context['loan_type_id'] ?? null) : null,
            'funding_product_id' => $usesProduct ? ($context['funding_product_id'] ?? null) : null,
            'year' => $usesYear ? (int) now()->format('Y') : null,
        ];
    }

    private static function format(string $format, array $context, int $sequence): string
    {
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

        return $number;
    }
}
