<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCashbookEntriesTable extends Migration
{
    public function up()
    {
        Schema::create('cashbook_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->date('entry_date');
            $table->string('type', 8); // In | Out
            $table->string('category', 32); // LoanCollection | Expense | Transfer | Deposit | Other
            $table->decimal('amount', 15, 2);
            /** Snapshot of the account's running balance right after this entry posted — not retroactively recalculated if earlier entries change. */
            $table->decimal('balance_after', 15, 2);
            $table->string('reference_type', 191)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('entry_date');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('cashbook_entries');
    }
}
