<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBankTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->date('txn_date');
            $table->string('type', 16); // Deposit | Withdrawal | TransferIn | TransferOut
            $table->string('reference_no', 64)->nullable();
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 15, 2);
            /** Snapshot of the account's running balance right after this entry posted — not retroactively recalculated if earlier entries change. */
            $table->decimal('balance_after', 15, 2);
            $table->string('status', 16)->default('Cleared');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('txn_date');
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_transactions');
    }
}
