<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the new "Banks" master (master_categories, type=bank — replaces the
 * unused "Bank Account Type" master, see MasterCategoryController::TYPES)
 * with a starter set of local banks, so the bank-name dropdowns across Bank
 * Accounts / Loan Payments (Cheque) / Fixed Deposits aren't empty right
 * after deploy. Admins can add/rename/deactivate entries afterward from
 * Masters > Banks like any other master list — this just seeds a starting
 * point. Checked-before-insert on [type, name] (the table's unique index),
 * so it's safe to run more than once.
 */
return new class extends Migration
{
    public function up(): void
    {
        $banks = [
            ['name' => 'Bank of Ceylon', 'code' => 'BOC'],
            ['name' => "People's Bank", 'code' => 'PB'],
            ['name' => 'Commercial Bank of Ceylon PLC', 'code' => 'COMB'],
            ['name' => 'Hatton National Bank PLC', 'code' => 'HNB'],
            ['name' => 'Sampath Bank PLC', 'code' => 'SAMP'],
            ['name' => 'Seylan Bank PLC', 'code' => 'SEY'],
            ['name' => 'National Savings Bank', 'code' => 'NSB'],
            ['name' => 'National Development Bank PLC', 'code' => 'NDB'],
            ['name' => 'DFCC Bank PLC', 'code' => 'DFCC'],
            ['name' => 'Nations Trust Bank PLC', 'code' => 'NTB'],
            ['name' => 'Pan Asia Banking Corporation PLC', 'code' => 'PABC'],
            ['name' => 'Union Bank of Colombo PLC', 'code' => 'UBC'],
            ['name' => 'Cargills Bank Ltd', 'code' => 'CARG'],
        ];

        $now = now();
        foreach ($banks as $i => $bank) {
            $exists = DB::table('master_categories')->where(['type' => 'bank', 'name' => $bank['name']])->exists();
            if ($exists) {
                continue;
            }
            DB::table('master_categories')->insert([
                'type' => 'bank',
                'name' => $bank['name'],
                'code' => $bank['code'],
                'description' => null,
                'status' => 'Active',
                'sort_order' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('master_categories')->where('type', 'bank')
            ->whereIn('code', ['BOC', 'PB', 'COMB', 'HNB', 'SAMP', 'SEY', 'NSB', 'NDB', 'DFCC', 'NTB', 'PABC', 'UBC', 'CARG'])
            ->delete();
    }
};
