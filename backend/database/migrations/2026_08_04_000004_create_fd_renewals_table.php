<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit/link row for FixedDepositController::renew() — no own reference
 * number, purely records old FD -> new FD plus the optional capital-
 * enhancement deposit that topped up the principal. The old FD's principal
 * itself never moves as cash (see FdLedgerPoster's docblock) — only
 * additional_deposit_amount, if any, is a real cash-in event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('old_fixed_deposit_id')->constrained('fixed_deposits');
            $table->foreignId('new_fixed_deposit_id')->unique()->constrained('fixed_deposits');
            $table->decimal('old_principal', 15, 2)->unsigned();
            $table->decimal('additional_deposit_amount', 15, 2)->unsigned()->default(0);
            $table->decimal('new_principal', 15, 2)->unsigned();
            $table->foreignId('additional_deposit_id')->nullable()->constrained('fd_deposits')->nullOnDelete();
            $table->smallInteger('new_term_months')->unsigned();
            $table->date('renewed_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('old_fixed_deposit_id');
            $table->index('additional_deposit_id');
        });

        DB::statement('ALTER TABLE `fd_renewals` ADD CONSTRAINT `chk_fdr_new_principal` CHECK (`new_principal` = `old_principal` + `additional_deposit_amount`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_renewals');
    }
};
