<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance module — Phase 6.
 *
 *  - finance_settings: a small key/value store for Finance-wide toggles and
 *    thresholds (e.g. require_cash_session). Seeded with the defaults so a
 *    missing row is never ambiguous.
 *
 *  - cash_sessions: a daily open/close for a cash account (a cashier's till
 *    or float). The cashier opens the day with a physical count, closes it
 *    with another count + denominations, and a manager verifies the
 *    variance. expected_closing is the account's current_balance at close
 *    time (the single source of truth every cash figure already uses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 64)->unique();
            $t->text('value')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->foreign('updated_by', 'fk_fset_updated_by')->references('id')->on('users')->nullOnDelete();
        });

        $now = now();
        DB::table('finance_settings')->insert([
            ['key' => 'require_cash_session', 'value' => '0', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'reversal_requires_approval', 'value' => '1', 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('cash_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cash_account_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->date('business_date');

            $t->decimal('opening_amount', 15, 2)->default(0);   // counted at open
            $t->decimal('opening_expected', 15, 2)->default(0); // current_balance at open (reference)
            $t->decimal('expected_closing', 15, 2)->nullable(); // current_balance at close
            $t->decimal('counted_closing', 15, 2)->nullable();
            $t->decimal('variance', 15, 2)->default(0);         // counted - expected
            $t->decimal('shortage', 15, 2)->default(0);
            $t->decimal('excess', 15, 2)->default(0);
            $t->json('denominations')->nullable();
            $t->decimal('coins_amount', 15, 2)->default(0);

            $t->enum('status', ['Open', 'Closed', 'Verified'])->default('Open');
            $t->string('notes', 255)->nullable();
            $t->unsignedBigInteger('opened_by')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->dateTime('opened_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->dateTime('verified_at')->nullable();
            $t->timestamps();

            $t->unique(['cash_account_id', 'business_date'], 'uq_cash_sessions_account_date');
            $t->index(['branch_id', 'business_date'], 'ix_cash_sessions_branch_date');
            $t->index('status', 'ix_cash_sessions_status');
            $t->foreign('cash_account_id', 'fk_cs_account')->references('id')->on('cash_accounts')->cascadeOnDelete();
            $t->foreign('branch_id', 'fk_cs_branch')->references('id')->on('branches')->nullOnDelete();
            $t->foreign('opened_by', 'fk_cs_opened_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('closed_by', 'fk_cs_closed_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('verified_by', 'fk_cs_verified_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('finance_settings');
    }
};
