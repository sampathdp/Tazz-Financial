<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the standard chart of accounts sized for what this system actually
 * does — lending plus customer fixed deposits — then creates one child leaf
 * account under 1010/1020 for every existing cash till / bank account and
 * links it via cash_till_links / bank_account_links (see that migration's
 * docblock for why this is a link, not a replacement).
 *
 * Deliberately NOT one GL account per expense_categories row: 5010 stays a
 * single control account and expense_category_id rides along on each journal
 * line's originating expense as an analysis dimension instead, so the COA
 * doesn't grow every time someone adds a category.
 *
 * Idempotent — safe to run more than once (checked-before-insert on `code`,
 * matching 2026_07_30_000002_seed_bank_master_categories' convention).
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        // code, name, type, normal_balance, parent_code
        ['1010', 'Cash in Hand', 'Asset', 'Debit', null],
        ['1020', 'Bank Accounts', 'Asset', 'Debit', null],
        ['1030', 'Cheques / Undeposited Funds', 'Asset', 'Debit', null],
        ['1100', 'Loan Principal Outstanding', 'Asset', 'Debit', null],
        ['1150', 'Interest Receivable', 'Asset', 'Debit', null],
        ['1190', 'Provision for Loan Losses', 'Asset', 'Credit', null], // contra-asset: normal balance is Credit
        ['1300', 'Fixed Assets', 'Asset', 'Debit', null],

        ['2010', 'Customer Fixed Deposits Payable', 'Liability', 'Credit', null],
        ['2020', 'Interest Payable on FDs', 'Liability', 'Credit', null],
        ['2300', 'Accrued Expenses', 'Liability', 'Credit', null],

        ['3010', 'Share Capital', 'Equity', 'Credit', null],
        ['3020', 'Retained Earnings', 'Equity', 'Credit', null],
        // Not posted to directly — populated at report time as the current
        // period's income minus expense total (see AccountingReportController).
        ['3030', 'Current Year Profit / Loss', 'Equity', 'Credit', null],

        ['4010', 'Interest Income - Loans', 'Income', 'Credit', null],
        ['4020', 'Loan Charges / Fees', 'Income', 'Credit', null],
        ['4030', 'Penalty Income', 'Income', 'Credit', null],
        ['4900', 'Other Income', 'Income', 'Credit', null],

        ['5010', 'Operating Expenses', 'Expense', 'Debit', null],
        ['5100', 'Interest Expense - FDs', 'Expense', 'Debit', null],
        ['5200', 'Staff Salaries & Commission', 'Expense', 'Debit', null],
        ['5300', 'Provision for Loan Losses', 'Expense', 'Debit', null],
    ];

    public function up(): void
    {
        $now = now();
        $idByCode = [];

        foreach (self::ACCOUNTS as [$code, $name, $type, $normalBalance, $parentCode]) {
            $existing = DB::table('chart_of_accounts')->where('code', $code)->first();
            if ($existing) {
                $idByCode[$code] = $existing->id;
                continue;
            }
            $idByCode[$code] = DB::table('chart_of_accounts')->insertGetId([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'normal_balance' => $normalBalance,
                'parent_id' => $parentCode ? ($idByCode[$parentCode] ?? null) : null,
                'is_system' => true,
                'status' => 'Active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (DB::table('cash_accounts')->get() as $till) {
            $code = "1010-{$till->id}";
            if (DB::table('cash_till_links')->where('cash_account_id', $till->id)->exists()) {
                continue;
            }
            $accountId = DB::table('chart_of_accounts')->insertGetId([
                'code' => $code,
                'name' => "Cash in Hand - {$till->name}",
                'type' => 'Asset',
                'normal_balance' => 'Debit',
                'parent_id' => $idByCode['1010'],
                'is_system' => false,
                'status' => $till->status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('cash_till_links')->insert([
                'account_id' => $accountId,
                'cash_account_id' => $till->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (DB::table('bank_accounts')->get() as $bank) {
            if (DB::table('bank_account_links')->where('bank_account_id', $bank->id)->exists()) {
                continue;
            }
            $code = "1020-{$bank->id}";
            $accountId = DB::table('chart_of_accounts')->insertGetId([
                'code' => $code,
                'name' => "Bank - {$bank->name}",
                'type' => 'Asset',
                'normal_balance' => 'Debit',
                'parent_id' => $idByCode['1020'],
                'is_system' => false,
                'status' => $bank->status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('bank_account_links')->insert([
                'account_id' => $accountId,
                'bank_account_id' => $bank->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally not reversed: dropping chart_of_accounts rows that
        // journal_lines may already reference by the time this would run
        // is unsafe. Roll back the table-creation migrations instead, which
        // drop chart_of_accounts (and everything referencing it) wholesale.
    }
};
