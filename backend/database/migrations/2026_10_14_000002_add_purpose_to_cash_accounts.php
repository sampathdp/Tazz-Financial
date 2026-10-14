<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A branch can hold several tills ("FD", "Doc charge", "Collection", ...). `purpose` says which kind
 * of cash a till is for, so each module's receipts and payouts land in the right one instead of
 * always the lowest-id till. Null / 'General' = a general-purpose till (the previous behaviour).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cash_accounts', 'purpose')) {
            return;
        }
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->string('purpose', 24)->nullable()->after('account_type');
            $table->index(['branch_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'purpose']);
            $table->dropColumn('purpose');
        });
    }
};
