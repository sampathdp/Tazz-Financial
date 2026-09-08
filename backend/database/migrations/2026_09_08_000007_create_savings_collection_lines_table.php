<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One account's line on a collection sheet. savings_transaction_id is set when
 * the sheet is Posted (the Deposit it created). An account may appear only
 * once per sheet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_collection_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('savings_collection_sheet_id')->constrained('savings_collection_sheets')->cascadeOnDelete();
            $table->foreignId('savings_account_id')->constrained('savings_accounts');
            $table->decimal('amount', 15, 2)->unsigned();
            $table->boolean('collected')->default(true);
            $table->string('reference_no', 64)->nullable();
            $table->foreignId('savings_transaction_id')->nullable()->constrained('savings_transactions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['savings_collection_sheet_id', 'savings_account_id'], 'uq_scl_sheet_account');
            $table->index('savings_account_id');
        });

        DB::statement('ALTER TABLE `savings_collection_lines` ADD CONSTRAINT `chk_savings_collection_line_amount` CHECK (`amount` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_collection_lines');
    }
};
