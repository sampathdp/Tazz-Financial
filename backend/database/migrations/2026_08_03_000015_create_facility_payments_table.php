<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merges loan_payments + speed_draft_payments.
 *
 * payment_type unifies two different mechanisms for the same underlying
 * idea: loan_payments had a plain `is_settlement` boolean and nothing else
 * (every other payment was an undifferentiated blended payment); speed_draft
 * payments already had a `payment_type` enum (Interest/CapitalReduction/
 * Settlement) that drives which of three completely different allocation
 * algorithms runs (see the merged FacilityPaymentAllocator dispatch by
 * facility_type — loan payments always run one blended waterfall
 * regardless of payment_type; only Speed Draft dispatches on it). 'Regular'
 * is the new default, standing in for "every payment a loan ever recorded
 * before this merge."
 *
 * approval_status/clearance_status/bank_id/cheque_date/realized_at/
 * approved_by/approved_at are loan-only in practice today (Speed Draft
 * payments apply immediately, with no settlement-approval step and no
 * cheque-clearance workflow) — kept nullable and simply always null for a
 * Speed Draft-originated row, rather than forcing Speed Draft to gain an
 * approval workflow it never had as a side effect of sharing a table.
 *
 * bank_id replaces loan_payments' free-text bank_name — a real reference to
 * the banks lookup table instead of a repeated, unconstrained string,
 * matching every other cheque-adjacent bank_name column in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_payments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 24)->unique();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities');
            $table->foreignId('branch_id')->constrained('branches');
            $table->decimal('amount', 15, 2)->unsigned();
            $table->enum('payment_type', ['Regular', 'Interest', 'CapitalReduction', 'Settlement'])->default('Regular');
            $table->enum('payment_method', ['Cash', 'Bank', 'Cheque'])->default('Cash');
            $table->enum('status', ['Active', 'Voided'])->default('Active');
            $table->enum('approval_status', ['Approved', 'PendingApproval', 'Rejected'])->default('Approved');
            $table->enum('clearance_status', ['Pending', 'Realized', 'Bounced'])->nullable();
            $table->string('receipt_no', 32)->nullable();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->date('cheque_date')->nullable();
            $table->date('realized_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('paid_at');
            $table->string('notes', 255)->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->timestamps();
            $table->index(['credit_facility_id', 'paid_at']);
            $table->index(['branch_id', 'paid_at']);
            $table->index('status');
        });

        DB::statement('ALTER TABLE `facility_payments` ADD CONSTRAINT `chk_fp_amount` CHECK (`amount` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_payments');
    }
};
