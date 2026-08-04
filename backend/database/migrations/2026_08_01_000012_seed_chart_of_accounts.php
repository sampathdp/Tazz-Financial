<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the standard chart of accounts sized for what this system actually
 * does — lending plus customer fixed deposits.
 *
 * Deliberately NOT one GL account per expense_categories row: 5010 stays a
 * single control account and expense_category_id rides along on each journal
 * line's originating expense as an analysis dimension instead, so the COA
 * doesn't grow every time someone adds a category.
 *
 * No longer backfills a leaf account per existing cash till / bank account:
 * cash_accounts/bank_accounts now carry chart_of_account_id directly (set at
 * creation via ChartOfAccount::forCashTill()/forBankAccount()) rather than
 * through a separate cash_till_links/bank_account_links table this migration
 * used to populate — and those two tables now run *after* this one in the
 * migration order regardless, so they wouldn't exist yet at this point.
 *
 * Idempotent — safe to run more than once (checked-before-insert on `code`).
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        // code, name, account_type, normal_balance, parent_code
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

        foreach (self::ACCOUNTS as [$code, $name, $accountType, $normalBalance, $parentCode]) {
            $existing = DB::table('chart_of_accounts')->where('code', $code)->first();
            if ($existing) {
                $idByCode[$code] = $existing->id;
                continue;
            }
            $idByCode[$code] = DB::table('chart_of_accounts')->insertGetId([
                'code' => $code,
                'name' => $name,
                'account_type' => $accountType,
                'normal_balance' => $normalBalance,
                'parent_id' => $parentCode ? ($idByCode[$parentCode] ?? null) : null,
                'is_system' => true,
                'status' => 'Active',
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
