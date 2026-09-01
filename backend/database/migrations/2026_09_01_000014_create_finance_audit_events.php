<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance module — Phase 8. A queryable audit trail for every sensitive
 * finance action: who did it, to what, the status it moved between, the
 * amount, the reason, and the IP / user agent. Append-only (no updated_at).
 *
 * This is in addition to the storage/logs/audit.log file the app already
 * writes — the file is for forensic grep, this table backs the Audit Log
 * screen and the Transaction Audit report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_audit_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('action', 64);                       // e.g. request.approved, transaction.executed, transaction.reversed
            $t->string('subject_type', 48)->nullable();     // FinanceRequest / FinanceTransaction / CashFloatSettlement / CashSession
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32)->nullable();
            $t->decimal('amount', 15, 2)->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('reason', 255)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->foreign('actor_id', 'fk_fae_actor')->references('id')->on('users')->nullOnDelete();
            $t->foreign('branch_id', 'fk_fae_branch')->references('id')->on('branches')->nullOnDelete();
            $t->index(['subject_type', 'subject_id'], 'ix_fae_subject');
            $t->index(['action', 'created_at'], 'ix_fae_action_date');
            $t->index(['branch_id', 'created_at'], 'ix_fae_branch_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_audit_events');
    }
};
