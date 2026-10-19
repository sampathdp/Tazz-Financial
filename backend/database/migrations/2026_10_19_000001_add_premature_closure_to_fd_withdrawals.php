<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full premature closure of an FD (before maturity). It reuses fd_withdrawals so
 * the void, voucher and GL plumbing are shared; these columns record how the
 * payout was reached: the whole principal earns the product's premature rate for
 * the days held, anything already paid out as monthly interest is netted off, and
 * a surplus is clawed back from the principal returned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fd_withdrawals', function (Blueprint $table) {
            $table->boolean('is_premature')->default(false);
            $table->decimal('premature_rate_percent', 6, 3)->nullable();
            $table->unsignedInteger('premature_held_days')->nullable();
            $table->decimal('premature_clawback', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('fd_withdrawals', fn (Blueprint $t) => $t->dropColumn(['is_premature', 'premature_rate_percent', 'premature_held_days', 'premature_clawback']));
    }
};
