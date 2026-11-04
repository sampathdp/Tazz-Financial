<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refundable expenses (e.g. a rent advance / security deposit paid to a vendor and returned at
 * the end of the term). A refundable expense is NOT a cost when incurred: approval books
 * Dr 1410 Refundable Deposits & Advances / Cr 2300, payment relieves 2300 as usual, and each
 * refund received books Dr cash/bank / Cr 1410. Any part that will not come back is
 * forfeited to the expense category's account (Dr category / Cr 1410) when the deposit is closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $t) {
            $t->boolean('is_refundable')->default(false)->after('amount');
            $t->date('expected_refund_date')->nullable()->after('is_refundable');
            $t->decimal('refunded_amount', 15, 2)->default(0)->after('expected_refund_date');
            $t->decimal('forfeited_amount', 15, 2)->default(0)->after('refunded_amount');
            $t->string('refund_status', 20)->nullable()->after('forfeited_amount'); // null = non-refundable
        });

        Schema::create('expense_refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_id')->constrained('expenses')->restrictOnDelete();
            $t->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $t->decimal('amount', 15, 2)->default(0);           // cash/bank received back
            $t->decimal('forfeited_amount', 15, 2)->default(0); // written off to expense on this entry
            $t->string('payment_method', 10)->nullable();
            $t->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $t->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $t->string('reference', 60)->nullable();
            $t->string('notes', 255)->nullable();
            $t->date('refunded_at');
            $t->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('finance_transaction_id')->nullable()->constrained('finance_transactions')->nullOnDelete();
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $t->timestamps();
        });

        if (!DB::table('chart_of_accounts')->where('code', '1410')->exists()) {
            DB::table('chart_of_accounts')->insert([
                'code' => '1410', 'name' => 'Refundable Deposits & Advances', 'account_type' => 'Asset', 'normal_balance' => 'Debit',
                'parent_id' => null, 'is_system' => true, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_refunds');
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropColumn(['is_refundable', 'expected_refund_date', 'refunded_amount', 'forfeited_amount', 'refund_status']);
        });
    }
};
