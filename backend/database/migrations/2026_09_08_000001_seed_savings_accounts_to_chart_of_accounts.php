<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Daily Savings — the running-balance passbook savings product the company
 * takes in from customers (a demand liability, unlike the fixed-term
 * 2010 Customer Fixed Deposits Payable).
 *
 *   2050  Customer Savings Deposits Payable    every savings account's balance,
 *                                              owed back to the depositor on demand
 *   5120  Interest Expense - Savings Deposits   interest credited to savings
 *                                              accounts, kept apart from 5100
 *                                              (FDs) and 5110 (Borrowings)
 *   4045  Savings Account Fee Income            ledger / withdrawal / dormancy
 *                                              fees charged on savings accounts
 *
 * Idempotent — checked-before-insert on `code`, same pattern as
 * 2026_08_30_000010_seed_borrowing_accounts_to_chart_of_accounts.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        ['2050', 'Customer Savings Deposits Payable', 'Liability', 'Credit'],
        ['5120', 'Interest Expense - Savings Deposits', 'Expense', 'Debit'],
        ['4045', 'Savings Account Fee Income', 'Income', 'Credit'],
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
