<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A premature partial withdrawal — the FD stays open on the reduced
 * principal (unlike fd_withdrawals, which always closes the FD). See
 * FixedDepositController::partPayment()'s docblock for the interest math:
 * the withdrawn portion earns interest at the product's (reduced) premature
 * rate for the days actually held, not the full contracted rate/term.
 *
 * *_before columns snapshot the FD's own figures immediately before this
 * transaction, solely so voidPartPayment() can restore them exactly — voiding
 * is only ever allowed on the FD's most recent part payment (enforced in the
 * controller), so this snapshot approach never has to reconcile stacked,
 * out-of-order reversals.
 *
 * fixed_deposit_id is deliberately NOT ->constrained() here: despite
 * 2026_08_01_000008_convert_legacy_myisam_tables_to_innodb claiming to have
 * converted fixed_deposits to InnoDB with enforced FKs, this environment's
 * fixed_deposits (and fd_interest_payments/fd_withdrawals/fd_renewals) are
 * still MyISAM with no FK actually present — an unrelated pre-existing drift
 * (see that migration's own docblock). An InnoDB table can't FK-reference a
 * MyISAM one, so matching those siblings' de facto unenforced-but-indexed
 * shape here is what lets this table coexist with them today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_part_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fixed_deposit_id');
            $table->string('voucher_no', 24)->unique();
            $table->decimal('principal_amount', 15, 2)->unsigned();
            $table->decimal('rate_percent', 6, 3)->unsigned();
            $table->unsignedInteger('held_days');
            $table->decimal('interest_entitlement', 15, 2)->unsigned()->default(0);
            $table->decimal('interest_paid', 15, 2)->unsigned()->default(0);
            $table->decimal('clawback_amount', 15, 2)->unsigned()->default(0);
            $table->decimal('net_amount_paid', 15, 2)->unsigned();
            $table->enum('payment_method', ['Cash', 'Bank', 'Cheque']);
            $table->string('reference_no', 64)->nullable();
            $table->text('notes')->nullable();
            $table->date('paid_at');
            $table->decimal('principal_before', 15, 2)->unsigned();
            $table->decimal('monthly_interest_before', 15, 2)->nullable();
            $table->decimal('total_interest_before', 15, 2)->unsigned();
            $table->decimal('maturity_amount_before', 15, 2)->unsigned();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['fixed_deposit_id', 'paid_at']);
        });

        DB::statement('ALTER TABLE `fd_part_payments` ADD CONSTRAINT `chk_fdpp_net` CHECK (`net_amount_paid` = `principal_amount` + `interest_paid` - `clawback_amount`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_part_payments');
    }
};
