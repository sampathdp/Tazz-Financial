<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A borrowing the company takes on — the liability-side counterpart of a
 * customer Fixed Deposit. Recorded as a plain transaction; no certificate is
 * printed. Four shapes, distinguished by `type`:
 *
 *  - Party            : a term borrowing from a named lender / finance house.
 *                       Cash comes in when it's recorded (posts like an FD
 *                       deposit); repaid over time via borrowing_transactions.
 *  - Overdraft        : a sanctioned OD facility on one of our own Current
 *                       bank_accounts. The facility itself is registered here
 *                       (limit = principal_amount, `bank_account_id` set); the
 *                       actual draw-downs happen as ordinary bank withdrawals
 *                       that push that account's balance negative (guarded to
 *                       -overdraft_limit — see the bank_accounts migration).
 *                       outstanding_amount is computed live from the linked
 *                       account's negative balance, not stored.
 *  - RePledgedPawning : funds raised by re-pledging pawned articles the company
 *                       is currently holding to an outside financier. The
 *                       specific in-house pawning tickets involved are listed
 *                       in borrowing_repledged_items.
 *  - Other            : anything else; free-text `source` carries the detail.
 *
 * `lender_name` / `source` / `purpose` / `principal_amount` are the four
 * fields the business asked to capture for every borrowing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrowings', function (Blueprint $table) {
            $table->id();
            $table->string('borrowing_number', 24)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->enum('type', ['Party', 'Overdraft', 'RePledgedPawning', 'Other']);
            $table->string('lender_name', 160);
            $table->string('source', 255)->nullable();
            $table->text('purpose')->nullable();
            $table->decimal('principal_amount', 15, 2)->unsigned();
            // Running balance still owed. For Overdraft it stays 0 here and is
            // derived from bank_accounts.current_balance at read time instead.
            $table->decimal('outstanding_amount', 15, 2)->unsigned()->default(0);
            $table->decimal('interest_rate_percent', 6, 3)->unsigned()->nullable();
            $table->date('borrowing_date');
            // How the borrowed cash was received (null for Overdraft, which has
            // no single receipt event). Only Cash posts to the GL/Cashbook, same
            // "silent no-op for Bank/Cheque" convention as every other poster.
            $table->enum('receipt_method', ['Cash', 'Bank', 'Cheque'])->nullable();
            $table->foreignId('receipt_cash_account_id')->nullable()->constrained('cash_accounts')->nullOnDelete();
            $table->foreignId('receipt_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            // Overdraft only: the Current account the facility is drawn on.
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('reference_no', 64)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['Active', 'Settled', 'Void'])->default('Active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('branch_id');
            $table->index('type');
            $table->index('status');
            $table->index('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrowings');
    }
};
