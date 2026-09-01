<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9 — retires the legacy Cash Transfers feature. It is fully superseded
 * by Finance > Transfers (finance_requests + FinancePostingService), and its
 * old CashTransferController moved till balances + posted a journal entry
 * without ever writing a finance_transactions row or a cashbook mirror — a
 * money path outside the one writer.
 *
 * Kept: the cash_transfers table and its 3 historical rows (read by
 * finance:backfill-transactions --source=transfers), the CashTransfer model,
 * and the dormant cash_transfer approval_type (historical approval_requests
 * still reference it).
 *
 * Dropped here: the 'Cash Transfers' permission module rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permIds = DB::table('permissions')->where('module', 'Cash Transfers')->pluck('id');
        if ($permIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permIds)->delete();
            DB::table('permissions')->whereIn('id', $permIds)->delete();
        }
    }

    public function down(): void
    {
        // Permissions::saveForRole() re-creates permission rows on demand; nothing to restore.
    }
};
