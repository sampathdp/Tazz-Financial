<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New leaf accounts for the full P&L waterfall (Inter-Branch Interest, the
 * non-lending income lines, Staff Cost's Salary/Allowances split, HO Expense
 * Absorption, VAT/NBT and Income Tax) — see AccountingReportController::
 * plWaterfall(). None of these are posted to by any Support/*Poster class;
 * they're booked through the existing manual Journal Entries screen like any
 * other account, so the P&L waterfall has somewhere to read a real figure
 * from as soon as one gets posted, instead of a structurally-missing row.
 * Idempotent, same shape as 2026_08_03_000004_add_speed_draft_accounts_to_chart_of_accounts.
 */
return new class extends Migration
{
    private const ACCOUNTS = [
        // code, name, account_type, normal_balance, parent_code
        ['4040', 'Inter Branch / Zonal Interest Income (Exp)', 'Income', 'Credit', null],
        ['4910', 'Documentation Income (Other Income)', 'Income', 'Credit', '4900'],
        ['4920', 'Real Estate Profit', 'Income', 'Credit', '4900'],
        ['4930', 'Refunds on Write-offs from General Treasury', 'Income', 'Credit', '4900'],

        ['5210', 'Salary / EPF / ETF', 'Expense', 'Debit', '5200'],
        ['5220', 'Staff Allowances', 'Expense', 'Debit', '5200'],
        ['5400', 'HO Expense Absorption', 'Expense', 'Debit', null],
        ['5500', 'VAT & NBT on Financial Services', 'Expense', 'Debit', null],
        ['5600', 'Income Tax', 'Expense', 'Debit', null],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::ACCOUNTS as [$code, $name, $accountType, $normalBalance, $parentCode]) {
            if (DB::table('chart_of_accounts')->where('code', $code)->exists()) {
                continue;
            }
            $parentId = $parentCode ? DB::table('chart_of_accounts')->where('code', $parentCode)->value('id') : null;
            DB::table('chart_of_accounts')->insert([
                'code' => $code,
                'name' => $name,
                'account_type' => $accountType,
                'normal_balance' => $normalBalance,
                'parent_id' => $parentId,
                'is_system' => true,
                'status' => 'Active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->whereIn('code', ['4040', '4910', '4920', '4930', '5210', '5220', '5400', '5500', '5600'])->delete();
    }
};
