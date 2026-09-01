<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance module — Phase 6. A branch cash reconciliation can now be verified
 * and locked: once a manager verifies it, the day's count is frozen (the
 * upsert in CashReconciliationController::store() refuses a locked row).
 * Verifying a reconciliation that carries a variance requires the
 * finance_reconciliation_variance approval authorisation, seeded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_reconciliations', function (Blueprint $t) {
            $t->enum('status', ['Draft', 'Verified'])->default('Draft')->after('variance');
            $t->unsignedBigInteger('verified_by')->nullable()->after('reconciled_by');
            $t->dateTime('verified_at')->nullable()->after('verified_by');
            $t->dateTime('locked_at')->nullable()->after('verified_at');
        });

        if (!DB::table('approval_types')->where('code', 'finance_reconciliation_variance')->exists()) {
            DB::table('approval_types')->insert([
                'code' => 'finance_reconciliation_variance',
                'name' => 'Cash Reconciliation Variance',
                'description' => 'Signing off a branch cash reconciliation that has a counted-vs-system difference.',
                'is_system' => true,
                'required_approvals' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('cash_reconciliations', function (Blueprint $t) {
            $t->dropColumn(['status', 'verified_by', 'verified_at', 'locked_at']);
        });
        $id = DB::table('approval_types')->where('code', 'finance_reconciliation_variance')->value('id');
        if ($id) {
            DB::table('approval_requests')->where('approval_type_id', $id)->delete();
            DB::table('approval_types')->where('id', $id)->delete();
        }
    }
};
