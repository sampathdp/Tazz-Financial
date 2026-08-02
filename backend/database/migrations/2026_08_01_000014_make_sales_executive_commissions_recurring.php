<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A percentage-type commission now pays out once per "cycle" of
        // required_installments paid installments, each priced only on the
        // interest collected since the previous cycle — so a loan can have
        // several commission rows over its life instead of exactly one.
        // Fixed-type commissions are unaffected: they still get a single row.
        //
        // Split into separate Schema::table calls (rather than one Blueprint) so
        // each runs as its own statement in a predictable order — Laravel always
        // runs a Blueprint's column additions before its other explicit commands
        // regardless of call order, which would otherwise reorder the index swap.
        Schema::table('sales_executive_commissions', function (Blueprint $table) {
            if (!Schema::hasColumn('sales_executive_commissions', 'cycle_no')) {
                $table->unsignedSmallInteger('cycle_no')->default(1)->after('loan_type_id');
            }
            if (!Schema::hasColumn('sales_executive_commissions', 'from_installment')) {
                // Paid-installment count baseline this cycle starts counting from —
                // only interest from installments after this point counts toward
                // it, and it becomes eligible once total paid installments reach
                // from_installment + required_installments.
                $table->unsignedSmallInteger('from_installment')->default(0)->after('cycle_no');
            }
        });

        // MySQL won't drop a unique index that's still backing the loan_id
        // foreign key, so add a plain index for the FK to fall back onto first.
        if (!$this->hasIndex('sales_executive_commissions_loan_id_index')) {
            Schema::table('sales_executive_commissions', function (Blueprint $table) {
                $table->index('loan_id', 'sales_executive_commissions_loan_id_index');
            });
        }

        if ($this->hasIndex('sales_executive_commissions_loan_id_unique')) {
            Schema::table('sales_executive_commissions', function (Blueprint $table) {
                $table->dropUnique(['loan_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('sales_executive_commissions', function (Blueprint $table) {
            $table->dropColumn(['cycle_no', 'from_installment']);
        });
        // Restore the unique constraint before dropping the plain index that was
        // standing in for it, so the FK always has an index to fall back on.
        Schema::table('sales_executive_commissions', function (Blueprint $table) {
            $table->unique('loan_id');
        });
        Schema::table('sales_executive_commissions', function (Blueprint $table) {
            $table->dropIndex('sales_executive_commissions_loan_id_index');
        });
    }

    private function hasIndex(string $name): bool
    {
        return count(DB::select('SHOW INDEXES FROM sales_executive_commissions WHERE Key_name = ?', [$name])) > 0;
    }
};
