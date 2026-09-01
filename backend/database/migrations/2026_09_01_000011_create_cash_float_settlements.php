<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance module — Phase 5. Cash float lifecycle.
 *
 * A cashier float is a cash_accounts row (account_type = CashierFloat,
 * responsible_user_id = the holder, parent_cash_account_id = the branch till
 * it was issued from). This table is the settlement record for one float
 * period: how much was issued into it, what was counted at close, the
 * shortage / excess that was posted, and what was returned.
 *
 * Also seeds the two P&L accounts a cash count variance posts to — mirroring
 * how 4050 Bad Debt Recovery sits opposite 5300 Provision.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach ([
            ['5310', 'Cash Shortage', 'Expense', 'Debit'],
            ['4905', 'Cash Overage', 'Income', 'Credit'],
        ] as [$code, $name, $type, $normal]) {
            if (!DB::table('chart_of_accounts')->where('code', $code)->exists()) {
                DB::table('chart_of_accounts')->insert([
                    'code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $normal,
                    'is_system' => 1, 'status' => 'Active', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        Schema::create('cash_float_settlements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cash_account_id');          // the float account
            $t->unsignedBigInteger('home_cash_account_id');     // the till it settles back to
            $t->unsignedBigInteger('custodian_id')->nullable();

            $t->decimal('issued_total', 15, 2)->default(0);     // sum funded into the float this period
            $t->decimal('expected_amount', 15, 2)->nullable();  // float current_balance at close
            $t->decimal('counted_amount', 15, 2)->nullable();   // physically counted
            $t->decimal('variance', 15, 2)->default(0);         // counted - expected
            $t->decimal('shortage', 15, 2)->default(0);
            $t->decimal('excess', 15, 2)->default(0);
            $t->decimal('returned_total', 15, 2)->default(0);

            $t->enum('status', ['Open', 'SettlementRequested', 'Settled', 'Cancelled'])->default('Open');
            $t->unsignedBigInteger('approval_request_id')->nullable();
            $t->string('notes', 255)->nullable();

            $t->unsignedBigInteger('opened_by')->nullable();
            $t->unsignedBigInteger('settled_by')->nullable();
            $t->dateTime('opened_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->timestamps();

            $t->foreign('cash_account_id', 'fk_cfs_float')->references('id')->on('cash_accounts')->cascadeOnDelete();
            $t->foreign('home_cash_account_id', 'fk_cfs_home')->references('id')->on('cash_accounts')->restrictOnDelete();
            $t->foreign('custodian_id', 'fk_cfs_custodian')->references('id')->on('users')->nullOnDelete();
            $t->foreign('approval_request_id', 'fk_cfs_approval')->references('id')->on('approval_requests')->nullOnDelete();
            $t->foreign('opened_by', 'fk_cfs_opened_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('settled_by', 'fk_cfs_settled_by')->references('id')->on('users')->nullOnDelete();
            $t->index(['cash_account_id', 'status'], 'ix_cfs_float_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_float_settlements');
        DB::table('chart_of_accounts')->whereIn('code', ['5310', '4905'])->delete();
    }
};
