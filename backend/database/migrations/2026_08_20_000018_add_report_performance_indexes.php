<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for report/list filters that had no usable index (see the data-load
 * speed audit, 2026-08-20): cashbook_entries was only indexed by
 * (cash_account_id, entry_date), so Cash Bank Reports' branch-scoped period
 * query fell back to a full scan whenever no till was picked; credit_facilities
 * had no index at all on disbursed_at/closed_at, so Loan Disbursement/Closed
 * Loans reports scanned every loan; facility_schedules' only due_date index is
 * (status, due_date) — unusable for Collection Reports' due-date-only range
 * filter since status isn't part of that query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashbook_entries', function (Blueprint $table) {
            $table->index(['branch_id', 'entry_date']);
        });

        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->index('disbursed_at');
            $table->index(['status', 'closed_at']);
        });

        Schema::table('facility_schedules', function (Blueprint $table) {
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('cashbook_entries', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'entry_date']);
        });

        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropIndex(['disbursed_at']);
            $table->dropIndex(['status', 'closed_at']);
        });

        Schema::table('facility_schedules', function (Blueprint $table) {
            $table->dropIndex(['due_date']);
        });
    }
};
