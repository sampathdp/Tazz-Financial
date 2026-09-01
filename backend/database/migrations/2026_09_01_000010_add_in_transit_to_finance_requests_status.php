<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Finance module — Phase 5. A cross-branch transfer sits at InTransit
 * between release() (sending branch) and receive() (destination branch) —
 * see FinanceRequestService. The status was on finance_transactions from the
 * start but missing from the finance_requests enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `finance_requests`
            MODIFY `status` ENUM('Draft','Submitted','PendingApproval','Approved','Rejected','ReadyForPayment','Processing','InTransit','Paid','Completed','Cancelled','Reversed') NOT NULL DEFAULT 'Draft'
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE `finance_requests`
            MODIFY `status` ENUM('Draft','Submitted','PendingApproval','Approved','Rejected','ReadyForPayment','Processing','Paid','Completed','Cancelled','Reversed') NOT NULL DEFAULT 'Draft'
        SQL);
    }
};
