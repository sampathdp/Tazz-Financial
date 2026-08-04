<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The double-entry core (spec 3.8, schema.sql SECTION 7). journal_entries is
 * one row per business event; journal_lines are its debit/credit rows, which
 * must sum to zero per entry — enforced in App\Support\LedgerPoster, the only
 * code allowed to write to either table.
 *
 * General Ledger, Trial Balance, Profit & Loss, Balance Sheet and the
 * indirect-method Cash Flow Statement are all *derived reports* queried over
 * journal_lines — none of them get their own stored table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->date('entry_date');
            // Which business record caused this posting. A closed, business-
            // meaningful enum instead of a raw Laravel class-name string (the
            // original `reference_type` stored e.g. "App\Models\LoanPayment" —
            // an implementation detail leaking into the database, silently wrong
            // the instant that class is renamed or namespaced differently).
            $table->enum('source_type', [
                'Loan', 'Customer', 'LoanPayment', 'SpeedDraft', 'SpeedDraftPayment',
                'FdDeposit', 'FixedDeposit', 'FdWithdrawal', 'FdInterestPayment', 'FdRenewal',
                'CashTransfer', 'Expense', 'PaymentVoucher', 'ManualAdjustment', 'OpeningBalance',
            ])->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('posted_at')->nullable();
            $table->enum('status', ['Posted', 'Reversed'])->default('Posted');
            // Set on the reversing entry, pointing back at the entry it reverses —
            // never edit or delete a posted entry, only reverse it (see LedgerPoster::reverse()).
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
            $table->index('entry_date');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            // Denormalized copy of the parent entry's entry_date — deliberate,
            // not an oversight. This table is the highest-write-volume table in
            // the system (every payment/expense/transfer posts >=2 rows here),
            // and its dominant query ("every line for account X between two
            // dates" — the account ledger / trial balance report) would
            // otherwise force a join to journal_entries on every row, forever.
            $table->date('entry_date');
            $table->decimal('debit', 15, 2)->unsigned()->default(0);
            $table->decimal('credit', 15, 2)->unsigned()->default(0);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->index(['account_id', 'entry_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `journal_lines` ADD CONSTRAINT `chk_journal_lines_one_side` CHECK (
                (`debit` = 0 AND `credit` > 0) OR (`debit` > 0 AND `credit` = 0)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
