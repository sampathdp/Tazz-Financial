<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spec 3.9 Expense Management (schema.sql SECTION 8), split the same way the
 * spec splits it: `expenses` is the approved liability (an expense that's
 * been incurred and signed off, whether or not it's been paid yet — this is
 * what account 2300 Accrued Expenses represents between approval and
 * payment); `payment_vouchers` is the actual cash/bank outflow that settles
 * one. Decoupling the two means an approved-but-unpaid expense is a real,
 * reportable liability, not just an implicit state on one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('expense_date');
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('Pending'); // Pending | Approved | Rejected | Paid
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 16)->default('Cash'); // Cash | Bank | Cheque
            $table->dateTime('paid_at');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            // Which till/bank account the money actually left — exactly one of
            // these is set, matching payment_method (Cash -> cash_account_id,
            // Bank/Cheque -> bank_account_id).
            $table->foreignId('cash_account_id')->nullable()->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->string('cheque_no', 32)->nullable();
            $table->string('status', 16)->default('Issued');
            // The GL posting this voucher created — see LedgerPoster.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_vouchers');
        Schema::dropIfExists('expenses');
    }
};
