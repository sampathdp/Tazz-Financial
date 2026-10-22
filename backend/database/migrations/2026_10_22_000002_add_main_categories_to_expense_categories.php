<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-level expense categories: a MAIN category (parent_id null) and optional sub-categories under it.
 * Both levels stay selectable on an expense (each carries its own GL account), so existing categories
 * and reports keep working; the parent only groups them. Idempotent - safe to re-run.
 */
return new class extends Migration
{
    /** code => [name, GL account code, sort order] */
    private const MAINS = [
        'MAIN-STAFF' => ['Salaries & Staff Costs', '5200', 10],
        'MAIN-COMM' => ['Commissions & Incentives', '5200', 20],
        'MAIN-RENT' => ['Rent & Utilities', '5010', 30],
        'MAIN-OFFICE' => ['Office & Administration', '5010', 40],
        'MAIN-MKT' => ['Marketing & Advertising', '5010', 50],
        'MAIN-TRAVEL' => ['Travel & Vehicle', '5010', 60],
        'MAIN-PROF' => ['Professional & Legal Fees', '5010', 70],
        'MAIN-BANK' => ['Bank & Finance Charges', '5010', 80],
        'MAIN-REPAIR' => ['Repairs & Maintenance', '5010', 90],
        'MAIN-INS' => ['Insurance', '5010', 100],
        'MAIN-TAX' => ['Taxes & Licences', '5010', 110],
        'MAIN-MISC' => ['Miscellaneous', '5010', 120],
    ];

    /** Existing category (by name) => main code it belongs under. */
    private const EXISTING = [
        'Salary' => 'MAIN-STAFF',
        'Staff allowance' => 'MAIN-STAFF',
        'Incentive FD' => 'MAIN-COMM',
        'Loan Incentive' => 'MAIN-COMM',
    ];

    public function up(): void
    {
        if (!Schema::hasColumn('expense_categories', 'parent_id')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->foreignId('parent_id')->nullable()->after('id')->constrained('expense_categories')->nullOnDelete();
            });
        }

        foreach (self::MAINS as $code => [$name, $glCode, $sort]) {
            if (DB::table('expense_categories')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('expense_categories')->insert([
                'name' => $name, 'code' => $code, 'description' => 'Main category',
                'chart_of_account_id' => DB::table('chart_of_accounts')->where('code', $glCode)->value('id'),
                'status' => 'Active', 'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (self::EXISTING as $name => $mainCode) {
            $mainId = DB::table('expense_categories')->where('code', $mainCode)->value('id');
            if ($mainId) {
                DB::table('expense_categories')->where('name', $name)->whereNull('parent_id')->where('id', '!=', $mainId)
                    ->whereNotIn('code', array_keys(self::MAINS))->update(['parent_id' => $mainId]);
            }
        }
    }

    public function down(): void
    {
        DB::table('expense_categories')->whereIn('code', array_keys(self::MAINS))->update(['parent_id' => null]);
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
        DB::table('expense_categories')->whereIn('code', array_keys(self::MAINS))->delete();
    }
};
