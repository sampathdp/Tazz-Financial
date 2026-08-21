<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-pawn history — no analog elsewhere in the schema, since Loan/SpeedDraft
 * don't roll forward this way. Each row is one renewal event: the customer
 * settles accrued interest (interest_settled), optionally reduces capital
 * (capital_before/capital_after), and the ticket's maturity moves to
 * new_due_date under applied_rate_percent. facility_payment_id links to the
 * actual money movement recorded on the shared facility_payments ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pawning_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->smallInteger('renewal_no')->unsigned();
            $table->date('old_due_date');
            $table->date('new_due_date');
            $table->decimal('capital_before', 15, 2)->unsigned();
            $table->decimal('interest_settled', 15, 2)->unsigned();
            $table->decimal('capital_after', 15, 2)->unsigned();
            $table->decimal('applied_rate_percent', 6, 3)->unsigned();
            $table->foreignId('facility_payment_id')->nullable()->constrained('facility_payments')->nullOnDelete();
            $table->foreignId('renewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('renewed_at');
            $table->string('remark', 255)->nullable();
            $table->unique(['credit_facility_id', 'renewal_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pawning_renewals');
    }
};
