<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 15, 2);
            /** Snapshot of the account's book balance at the time this reconciliation was recorded. */
            $table->decimal('book_balance', 15, 2);
            $table->decimal('difference', 15, 2)->default(0);
            $table->enum('status', ['Pending', 'Reconciled'])->default('Pending');
            $table->string('notes', 255)->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reconciled_at')->nullable();
            $table->timestamps();
            // Was missing entirely — nothing stopped the same statement date
            // being reconciled twice for one account. cash_reconciliations
            // already had the equivalent constraint; this brings the two
            // structurally-parallel tables in line with each other.
            $table->unique(['bank_account_id', 'statement_date']);
            $table->index('status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `bank_reconciliations` ADD CONSTRAINT `chk_br_difference`
            CHECK (`difference` = `statement_balance` - `book_balance`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
