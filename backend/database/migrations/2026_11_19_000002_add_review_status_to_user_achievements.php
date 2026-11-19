<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Achievements now need an admin's sign-off. New/edited rows start as Pending; an admin
 * approves or rejects them from the Approvals inbox. Rows that existed before this change
 * are treated as already approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_achievements', 'status')) {
            return;
        }
        Schema::table('user_achievements', function (Blueprint $t) {
            $t->string('status', 20)->default('Pending')->after('achieved_on');
            $t->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $t->string('review_note', 500)->nullable()->after('reviewed_at');
            $t->index('status');
        });
        DB::table('user_achievements')->update(['status' => 'Approved']);
    }

    public function down(): void
    {
        if (!Schema::hasColumn('user_achievements', 'status')) {
            return;
        }
        Schema::table('user_achievements', function (Blueprint $t) {
            $t->dropForeign(['reviewed_by']);
            $t->dropIndex(['status']);
            $t->dropColumn(['status', 'reviewed_by', 'reviewed_at', 'review_note']);
        });
    }
};
