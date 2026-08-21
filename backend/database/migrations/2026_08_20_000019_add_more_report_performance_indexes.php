<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second batch from the system-wide data-load speed audit (2026-08-20):
 * journal_entries had no index usable for JournalEntryController::index()'s
 * branch_id/status filters (only ['source_type','source_id'] and entry_date
 * existed); expenses' only status index is the composite ['branch_id','status'],
 * unusable for a status-only filter; sales_executive_commissions had no index
 * on loan_type_id at all, despite being a filter column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['branch_id', 'status', 'entry_date']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('sales_executive_commissions', function (Blueprint $table) {
            $table->index('loan_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'status', 'entry_date']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('sales_executive_commissions', function (Blueprint $table) {
            $table->dropIndex(['loan_type_id']);
        });
    }
};
