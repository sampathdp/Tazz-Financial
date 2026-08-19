<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A payment can now be recorded with a cashier-chosen manual split (Interest/
 * Capital/OD Charges/Other Charges) instead of the automatic waterfall — see
 * LoanPaymentAllocator::applyManualAllocation(). Like Settlement, it's held
 * PendingApproval (approval_status) and not applied to the schedule until a
 * top-level user approves it (see the payment_manual_allocation approval type,
 * seeded in the next migration). manual_allocation is the requested split,
 * the source of truth applyManualAllocation() consumes once approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `facility_payments`
            MODIFY `payment_type` ENUM('Regular', 'Interest', 'CapitalReduction', 'Settlement', 'ManualAllocation') DEFAULT 'Regular'
        SQL);

        Schema::table('facility_payments', function (Blueprint $table) {
            $table->json('manual_allocation')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('facility_payments', function (Blueprint $table) {
            $table->dropColumn('manual_allocation');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `facility_payments`
            MODIFY `payment_type` ENUM('Regular', 'Interest', 'CapitalReduction', 'Settlement') DEFAULT 'Regular'
        SQL);
    }
};
