<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Borrowings — money the company itself borrows (the mirror image of the
 * customer Fixed Deposits it takes in). Each funding source gets its own
 * liability control account so the Balance Sheet shows what was borrowed and
 * from where, rather than lumping everything into one line:
 *
 *   2200  Borrowings - Party / Institutional   a named lender / another finance house
 *   2210  Bank Overdrafts                       a sanctioned OD drawn on a current account
 *   2130  Re-Pledged Pawning Liability          funds raised by re-pledging pawned articles
 *                                               the company is holding, to an outside financier
 *
 *   5110  Interest Expense - Borrowings         interest paid on any of the above, kept
 *                                               apart from 5100 Interest Expense - FDs
 *
 * (2100 / 2110 are already taken — Unapplied Loan Receipts / Pawning Auction
 * Surplus Payable — hence the 2200 block.)
 *
 * Idempotent — checked-before-insert on `code`, same pattern as
 * 2026_08_27_000005_seed_bad_debt_recovery_account.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        ['2200', 'Borrowings - Party / Institutional', 'Liability', 'Credit'],
        ['2210', 'Bank Overdrafts', 'Liability', 'Credit'],
        ['2130', 'Re-Pledged Pawning Liability', 'Liability', 'Credit'],
        ['5110', 'Interest Expense - Borrowings', 'Expense', 'Debit'],
    ];

    public function up(): void
    {
        foreach (self::ACCOUNTS as [$code, $name, $type, $normalBalance]) {
            if (DB::table('chart_of_accounts')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('chart_of_accounts')->insert([
                'code' => $code,
                'name' => $name,
                'account_type' => $type,
                'normal_balance' => $normalBalance,
                'parent_id' => null,
                'is_system' => true,
                'status' => 'Active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Not reversed — journal_lines may already reference these by the time
        // this would run, same rationale as the original COA seed migration.
    }
};
