<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBankReconciliationsTable extends Migration
{
    public function up()
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 15, 2);
            /** Snapshot of the account's book balance at the time this reconciliation was recorded. */
            $table->decimal('book_balance', 15, 2);
            $table->decimal('difference', 15, 2)->default(0);
            $table->string('status', 16)->default('Pending');
            $table->string('notes', 255)->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_reconciliations');
    }
}
