<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for a loan reschedule — one row per reschedule event, written
 * by LoanController::reschedule(). Records the snapshot of what the old loan
 * still owed (capital / unpaid interest / OD / other charges), how the
 * unpaid interest split (earned/overdue interest is capitalised into the new
 * principal and recognised as income; unearned future interest is written
 * off, never recognised — see the accounting note in
 * LoanLedgerPoster::postRescheduleRollover()), whether OD was retained or
 * waived, the manually added charge lines (JSON), and the resulting new
 * loan's terms. Nothing here is a ledger — the GL side is one
 * 'LoanReschedule' journal entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->foreignId('new_credit_facility_id')->nullable()->constrained('credit_facilities')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('branches');
            $table->date('as_of_date');

            // Snapshot of the old loan's outstanding, by bucket, as of as_of_date.
            $table->decimal('capital_outstanding', 15, 2);
            $table->decimal('unpaid_interest', 15, 2);      // overdue + not-yet-due, total
            $table->decimal('overdue_interest', 15, 2);     // the earned / capitalisable slice
            $table->decimal('default_charges', 15, 2);
            $table->decimal('other_charges', 15, 2);

            // How the unpaid interest was split, plus the other roll-in decisions.
            $table->decimal('interest_deduction', 15, 2)->default(0);       // extra earned interest the user chose to waive
            $table->decimal('capitalised_interest', 15, 2)->default(0);     // rolled into new principal + recognised as income
            $table->decimal('interest_written_off', 15, 2)->default(0);     // unearned future interest + any waived earned interest; NOT recognised
            $table->enum('default_charges_action', ['retain', 'waive']);
            $table->decimal('retained_default_charges', 15, 2)->default(0);
            $table->decimal('other_charges_rolled', 15, 2)->default(0);
            $table->decimal('added_charges_total', 15, 2)->default(0);
            $table->json('added_charges')->nullable();

            // Resulting new loan.
            $table->decimal('new_principal', 15, 2);
            $table->decimal('new_interest_rate_percent', 6, 3);
            $table->smallInteger('new_term_months')->unsigned();
            $table->enum('new_interest_method', ['flat', 'reducing_balance']);
            $table->enum('new_installment_frequency', ['daily', 'weekly', 'monthly']);

            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('credit_facility_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_reschedules');
    }
};
