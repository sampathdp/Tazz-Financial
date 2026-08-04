<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The loan-specific half of a credit_facilities row — see that migration's
 * docblock. confirmed_date/confirmed_by are carried over unchanged even
 * though nothing in the current codebase ever writes to them (verified by a
 * full grep of app/) — they're pre-existing schema/relation scaffolding for
 * a confirmation step that was never wired up, not something this merge
 * should quietly drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_facility_details', function (Blueprint $table) {
            $table->foreignId('credit_facility_id')->primary()->constrained('credit_facilities')->cascadeOnDelete();
            $table->foreignId('loan_type_id')->constrained('loan_types');
            $table->decimal('down_payment', 15, 2)->unsigned()->default(0);
            $table->enum('interest_method', ['flat', 'reducing_balance']);
            $table->enum('installment_frequency', ['daily', 'weekly', 'monthly'])->default('monthly');
            $table->string('purpose', 255)->nullable();
            $table->date('application_date')->nullable();
            $table->string('reference_name', 150)->nullable();
            $table->string('reference_id_no', 64)->nullable();
            $table->string('reference_address', 255)->nullable();
            $table->foreignId('loan_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recovery_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('first_installment_date')->nullable();
            $table->date('freeze_date')->nullable();
            $table->date('confirmed_date')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('loan_officer_id');
            $table->index('recovery_officer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_facility_details');
    }
};
