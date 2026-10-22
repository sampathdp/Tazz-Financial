<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the remaining main expense categories for a finance / leasing company (IT, depreciation,
 * recovery costs, donations) and the usual sub-categories under every main. Idempotent: rows are keyed
 * by code, so re-running (or editing names in Masters afterwards) never duplicates anything.
 */
return new class extends Migration
{
    /** code => [name, GL account code, sort order] */
    private const NEW_MAINS = [
        'MAIN-IT' => ['IT & Communication', '5010', 55],
        'MAIN-RECOV' => ['Recovery & Collection Costs', '5010', 75],
        'MAIN-DEPR' => ['Depreciation & Amortisation', '5010', 115],
        'MAIN-CSR' => ['Donations & CSR', '5010', 118],
    ];

    /** main code => [[sub code, name, GL account code (null = inherit the main's)], ...] */
    private const SUBS = [
        'MAIN-STAFF' => [
            ['SUB-EPF', 'EPF / ETF Contribution', '5210'],
            ['SUB-BONUS', 'Bonus', null],
            ['SUB-OT', 'Overtime', null],
            ['SUB-WELFARE', 'Staff Welfare & Refreshments', null],
            ['SUB-TRAIN', 'Training & Development', null],
            ['SUB-UNIFORM', 'Uniforms', null],
        ],
        'MAIN-COMM' => [
            ['SUB-SALESCOMM', 'Sales Executive Commission', null],
        ],
        'MAIN-RENT' => [
            ['SUB-RENT', 'Office / Branch Rent', null],
            ['SUB-ELEC', 'Electricity', null],
            ['SUB-WATER', 'Water', null],
            ['SUB-SECURITY', 'Security Services', null],
        ],
        'MAIN-OFFICE' => [
            ['SUB-STATION', 'Stationery & Printing', null],
            ['SUB-POST', 'Postage & Courier', null],
            ['SUB-CLEAN', 'Cleaning & Sanitation', null],
            ['SUB-SUBS', 'Subscriptions & Memberships', null],
            ['SUB-ENTERT', 'Entertainment & Hospitality', null],
        ],
        'MAIN-MKT' => [
            ['SUB-ADS', 'Advertising', null],
            ['SUB-PROMO', 'Promotions & Events', null],
            ['SUB-BRAND', 'Branding & Signage', null],
        ],
        'MAIN-TRAVEL' => [
            ['SUB-FUEL', 'Fuel', null],
            ['SUB-VEHMAINT', 'Vehicle Maintenance', null],
            ['SUB-TRAVEL', 'Travelling & Accommodation', null],
            ['SUB-VEHLIC', 'Vehicle Licences & Parking', null],
        ],
        'MAIN-PROF' => [
            ['SUB-AUDIT', 'Audit Fees', null],
            ['SUB-LEGAL', 'Legal Fees', null],
            ['SUB-CONSULT', 'Consultancy Fees', null],
            ['SUB-VALUATION', 'Valuation Fees', null],
        ],
        'MAIN-BANK' => [
            ['SUB-BANKCHG', 'Bank Charges', null],
            ['SUB-TRANSFEE', 'Fund Transfer & Cheque Fees', null],
            ['SUB-CRIB', 'CRIB / Credit Bureau Charges', null],
        ],
        'MAIN-REPAIR' => [
            ['SUB-BLDG', 'Building Repairs', null],
            ['SUB-EQUIP', 'Equipment Repairs', null],
            ['SUB-FURN', 'Furniture & Fittings', null],
        ],
        'MAIN-INS' => [
            ['SUB-INSPROP', 'Property & Fire Insurance', null],
            ['SUB-INSVEH', 'Vehicle Insurance', null],
            ['SUB-INSFID', 'Fidelity / Staff Insurance', null],
        ],
        'MAIN-TAX' => [
            ['SUB-BIZLIC', 'Business Licences & Registration', null],
            ['SUB-STAMP', 'Stamp Duty', null],
            ['SUB-RATES', 'Local Authority Rates', null],
        ],
        'MAIN-MISC' => [
            ['SUB-OTHER', 'Other Expenses', null],
        ],
        'MAIN-IT' => [
            ['SUB-SOFT', 'Software & Licences', null],
            ['SUB-NET', 'Telephone & Internet', null],
            ['SUB-HARD', 'IT Hardware & Supplies', null],
            ['SUB-HOST', 'Hosting & Cloud Services', null],
        ],
        'MAIN-RECOV' => [
            ['SUB-REPO', 'Repossession Costs', null],
            ['SUB-YARD', 'Yard & Storage Charges', null],
            ['SUB-FIELD', 'Field Recovery Expenses', null],
            ['SUB-COURT', 'Court & Legal Recovery Fees', null],
        ],
        'MAIN-DEPR' => [
            ['SUB-DEPRPPE', 'Depreciation - Property & Equipment', null],
            ['SUB-AMORT', 'Amortisation - Software', null],
        ],
        'MAIN-CSR' => [
            ['SUB-DONATE', 'Donations', null],
            ['SUB-CSR', 'CSR Activities', null],
        ],
    ];

    public function up(): void
    {
        foreach (self::NEW_MAINS as $code => [$name, $gl, $sort]) {
            if (!DB::table('expense_categories')->where('code', $code)->exists()) {
                DB::table('expense_categories')->insert([
                    'name' => $name, 'code' => $code, 'description' => 'Main category',
                    'chart_of_account_id' => $this->glId($gl),
                    'status' => 'Active', 'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach (self::SUBS as $mainCode => $subs) {
            $main = DB::table('expense_categories')->where('code', $mainCode)->first(['id', 'chart_of_account_id', 'sort_order']);
            if (!$main) {
                continue;
            }
            foreach ($subs as $i => [$code, $name, $gl]) {
                if (DB::table('expense_categories')->where('code', $code)->exists()) {
                    continue;
                }
                DB::table('expense_categories')->insert([
                    'parent_id' => $main->id, 'name' => $name, 'code' => $code,
                    'chart_of_account_id' => $gl ? ($this->glId($gl) ?? $main->chart_of_account_id) : $main->chart_of_account_id,
                    'status' => 'Active', 'sort_order' => $main->sort_order + $i + 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $codes = array_merge(array_keys(self::NEW_MAINS), array_merge(...array_map(fn ($s) => array_column($s, 0), array_values(self::SUBS))));
        // Only remove rows that were never used by an expense; used ones are kept.
        DB::table('expense_categories')->whereIn('code', $codes)
            ->whereNotIn('id', DB::table('expenses')->whereNotNull('expense_category_id')->select('expense_category_id'))
            ->delete();
    }

    private function glId(string $code): ?int
    {
        $id = DB::table('chart_of_accounts')->where('code', $code)->value('id');

        return $id ? (int) $id : null;
    }
};
