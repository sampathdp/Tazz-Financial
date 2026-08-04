<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merges `loans` and `speed_drafts` — two structurally near-identical
 * lending products (both: apply, approve/reject, disburse, amortize into an
 * installment schedule, collect payments, close) that had been built as two
 * independent copies of the same shape. Confirmed by the real code, not just
 * the schema: both use the exact same status vocabulary
 * (Pending/Approved/Rejected/Active/Closed, plus reserved Restructured/
 * WrittenOff), the same branch/customer/principal/rate/term/disbursement
 * columns, and near-identical controller flows (see LoanController vs
 * SpeedDraftController).
 *
 * What's genuinely product-specific (down_payment, interest_method,
 * reference person, officer assignment for Loan; disbursement_method for
 * SpeedDraft) moves to loan_facility_details / speed_draft_facility_details
 * — 1:1 extension tables keyed on this row's own id — rather than bloating
 * this table with columns that are always null for one product or the
 * other. `facility_type` is the structural discriminator that says which
 * extension table has this row's other half.
 *
 * outstanding_balance_cache is new for what used to be Loan (SpeedDraft
 * already maintained this as `outstanding_capital`) — a maintained cache of
 * LoanPaymentAllocator::outstandingBalance()'s result, kept for the same
 * list-view performance reason as bank/cash account current_balance:
 * recomputing a full schedule+charges+waivers sum for every row of every
 * facility list would be expensive at scale. Source of truth is always the
 * live calculation; this is a cache of it, not a second ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_facilities', function (Blueprint $table) {
            $table->id();
            $table->char('public_uuid', 36);
            $table->string('code', 24)->unique();
            $table->enum('facility_type', ['Loan', 'SpeedDraft']);
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained('customers');
            $table->decimal('principal_amount', 15, 2)->unsigned();
            $table->decimal('interest_rate_percent', 6, 3)->unsigned();
            $table->enum('rate_period', ['monthly', 'annual'])->default('monthly');
            $table->smallInteger('term_months')->unsigned();
            $table->date('start_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->decimal('disbursed_amount', 15, 2)->unsigned()->nullable();
            $table->dateTime('disbursed_at')->nullable();
            $table->decimal('outstanding_balance_cache', 15, 2)->default(0);
            $table->enum('status', ['Pending', 'Approved', 'Rejected', 'Active', 'Closed', 'Restructured', 'WrittenOff'])->default('Pending');
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('rejected_reason', 255)->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('public_uuid');
            $table->index(['facility_type', 'status', 'branch_id']);
            $table->index('customer_id');
        });

        DB::statement('ALTER TABLE `credit_facilities` ADD CONSTRAINT `chk_cf_principal` CHECK (`principal_amount` > 0)');
        DB::statement('ALTER TABLE `credit_facilities` ADD CONSTRAINT `chk_cf_term` CHECK (`term_months` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_facilities');
    }
};
