<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('to_cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->date('transfer_date');
            $table->decimal('amount', 15, 2)->unsigned();
            $table->string('description', 255)->nullable();
            $table->enum('status', ['Pending', 'Completed', 'Rejected'])->default('Pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        // Was already enforced at the request-validation layer
        // ('different:to_cash_account_id') — this is a backstop, not a
        // behavior change: a transfer can't move money to the account it
        // came from.
        DB::statement(<<<'SQL'
            ALTER TABLE `cash_transfers` ADD CONSTRAINT `chk_ct_distinct_accounts`
            CHECK (`from_cash_account_id` <> `to_cash_account_id`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transfers');
    }
};
