<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renamed from generated_numbers — an immutable, append-only audit trail of
 * every number NumberGenerator has ever issued (which matters for a
 * regulated lender: reference numbers must be gap-free and traceable, which
 * the number_sequences counter alone doesn't prove after the fact).
 *
 * The original table repeated number_sequences' whole key (module, branch_id,
 * loan_type_id, funding_product_id, year) on every single row purely to
 * record which counter series a number came from — the same five-column
 * combination duplicated once per issued number instead of once per series.
 * Replaced with one FK back to the number_sequences row it was drawn from.
 *
 * source_type/source_id stay nullable, matching the original nullableMorphs:
 * a number is issued (and this row created) before the record it names
 * exists — Loan::create(['code' => NumberGenerator::next('loan'), ...]) needs
 * the code before the loan has an id — so NumberGenerator::attach() fills
 * these in a moment later, once that id exists. No updated_at: nothing about
 * an issued number is ever edited after the fact, only (once) completed by
 * attach().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('number_sequence_id')->constrained('number_sequences')->restrictOnDelete();
            $table->string('generated_number', 64)->unique();
            $table->enum('source_type', [
                'Loan', 'Customer', 'LoanPayment', 'SpeedDraft', 'SpeedDraftPayment',
                'FdDeposit', 'FixedDeposit', 'FdWithdrawal', 'JournalEntry', 'Expense', 'PaymentVoucher',
            ])->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->useCurrent();
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_log');
    }
};
