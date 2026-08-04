<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashbook_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            // Added — same rationale as bank_transactions.journal_entry_id:
            // nullable, not yet written anywhere (App\Support\CashbookPoster
            // posts to cash_accounts.current_balance only, entirely separate
            // from LoanLedgerPoster's parallel GL posting for the same
            // payment — see AccountingReportController::ledgerDrift()).
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->date('entry_date');
            $table->enum('direction', ['In', 'Out']);
            // 'Loan Collection' is the only value any backend code writes
            // today (App\Support\CashbookPoster, from a Cash-method loan
            // payment). Expense/Transfer/Deposit/Other exist only as
            // choices on the manual entry form (CashbookEntryController) —
            // kept as-is rather than renamed toward the future
            // loans/speed-drafts merge, since nothing here is merged yet.
            $table->enum('category', ['Loan Collection', 'Expense', 'Transfer', 'Deposit', 'Other']);
            $table->decimal('amount', 15, 2)->unsigned();
            /** Snapshot of the account's running balance right after this entry posted — not retroactively recalculated if earlier entries change. */
            $table->decimal('balance_after', 15, 2);
            // Closed, business-meaningful enum instead of a raw Laravel
            // class-name string — same fix as journal_entries.source_type.
            // Only 'LoanPayment' is written today; the rest are reserved for
            // when Expense/CashTransfer/FD flows start posting here too.
            $table->enum('source_type', ['LoanPayment', 'Expense', 'CashTransfer', 'FdDeposit', 'FdWithdrawal', 'ManualAdjustment'])->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['cash_account_id', 'entry_date']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashbook_entries');
    }
};
