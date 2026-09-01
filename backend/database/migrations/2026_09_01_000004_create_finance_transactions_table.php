<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance module — Phase 1. The immutable money-movement ledger.
 *
 * Every actual movement of physical cash or bank money produces exactly one
 * row here, written by App\Support\FinancePostingService (the single writer)
 * in the same DB transaction as its general-ledger entry. Each row is 1:1
 * with a journal_entries row (journal_entry_id) — Finance owns "money moved
 * from account X", the GL owns "what that means in debits and credits".
 *
 * Append-only: once status = Completed, amount / accounts / journal_entry_id
 * are never updated. Corrections are a new REVERSAL row pointing back via
 * reversal_of_id, mirroring LedgerPoster::reverse().
 *
 * finance_request_id has no FK yet — finance_requests arrives in Phase 2;
 * the column is added now so the posting service can populate it from day
 * one and a COLLECTION (which never has a request) can leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();

            $t->enum('txn_type', [
                'CASH_IN', 'CASH_OUT', 'TRANSFER', 'DISBURSEMENT', 'EXPENSE_PAYMENT',
                'FLOAT_ISSUE', 'FLOAT_RETURN', 'COLLECTION', 'BANK_DEPOSIT',
                'BANK_WITHDRAWAL', 'ADJUSTMENT', 'REVERSAL',
            ]);
            $t->enum('direction', ['In', 'Out', 'Move']);

            $t->enum('source_account_type', ['cash', 'bank'])->nullable();
            $t->unsignedBigInteger('source_account_id')->nullable();
            $t->enum('dest_account_type', ['cash', 'bank'])->nullable();
            $t->unsignedBigInteger('dest_account_id')->nullable();

            $t->decimal('amount', 15, 2);
            $t->char('currency', 3)->default('LKR');

            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('finance_request_id')->nullable();
            $t->string('source_module', 64)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->unsignedBigInteger('journal_entry_id')->nullable();

            $t->enum('status', ['Draft', 'Pending', 'Completed', 'InTransit', 'Reversed'])->default('Completed');
            $t->unsignedBigInteger('reversal_of_id')->nullable();
            $t->string('idempotency_key', 80)->nullable()->unique();

            $t->string('description', 255)->nullable();
            $t->json('meta')->nullable();

            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->unsignedBigInteger('executed_by')->nullable();
            $t->unsignedBigInteger('received_by')->nullable();
            $t->dateTime('executed_at')->nullable();
            $t->dateTime('received_at')->nullable();

            $t->timestamps();

            $t->foreign('branch_id', 'fk_ftx_branch')->references('id')->on('branches')->nullOnDelete();
            $t->foreign('journal_entry_id', 'fk_ftx_journal_entry')->references('id')->on('journal_entries')->nullOnDelete();
            $t->foreign('reversal_of_id', 'fk_ftx_reversal_of')->references('id')->on('finance_transactions')->nullOnDelete();
            $t->foreign('requested_by', 'fk_ftx_requested_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('approved_by', 'fk_ftx_approved_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('executed_by', 'fk_ftx_executed_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('received_by', 'fk_ftx_received_by')->references('id')->on('users')->nullOnDelete();

            $t->index(['source_account_type', 'source_account_id', 'executed_at'], 'ix_ftx_source_account');
            $t->index(['dest_account_type', 'dest_account_id', 'executed_at'], 'ix_ftx_dest_account');
            $t->index(['branch_id', 'executed_at'], 'ix_ftx_branch_date');
            $t->index(['txn_type', 'status'], 'ix_ftx_type_status');
            $t->index(['source_module', 'source_id'], 'ix_ftx_source');
        });

        // amount must be strictly positive — direction/txn_type carry the sign.
        Schema::getConnection()->statement('ALTER TABLE `finance_transactions` ADD CONSTRAINT `chk_ftx_amount` CHECK (`amount` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transactions');
    }
};
