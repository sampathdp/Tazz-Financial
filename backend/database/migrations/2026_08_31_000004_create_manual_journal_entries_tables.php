<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manual (adjusting / opening-balance / correcting) journal entries, with a
 * maker-checker gate on the shared approval_requests engine (code
 * 'manual_journal_entry'). A staging pair — never journal_entries directly —
 * so LedgerPoster stays the single writer of the real ledger: on approval the
 * controller feeds the staged lines through LedgerPoster::post() and stamps
 * journal_entry_id back here.
 *
 * There was previously NO way to post an opening balance or a correcting
 * entry in-app at all (JournalEntryController is read-only) — corrections
 * needed raw SQL. This closes that gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->date('entry_date');
            $table->string('description', 255)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('status', ['PendingApproval', 'Approved', 'Rejected'])->default('PendingApproval');
            $table->foreignId('requested_by')->constrained('users');
            $table->string('reason', 255)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('reject_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['status', 'entry_date']);
        });

        Schema::create('manual_journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manual_journal_entry_id')->constrained('manual_journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('description', 255)->nullable();
        });

        if (!DB::table('approval_types')->where('code', 'manual_journal_entry')->exists()) {
            DB::table('approval_types')->insert([
                'code' => 'manual_journal_entry',
                'name' => 'Manual Journal Entry',
                'description' => 'Approving a manually-authored journal entry before it posts to the general ledger.',
                'is_system' => true,
                'required_approvals' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('approval_requests')
            ->whereIn('approval_type_id', DB::table('approval_types')->where('code', 'manual_journal_entry')->pluck('id'))
            ->delete();
        DB::table('approval_types')->where('code', 'manual_journal_entry')->delete();
        Schema::dropIfExists('manual_journal_entry_lines');
        Schema::dropIfExists('manual_journal_entries');
    }
};
