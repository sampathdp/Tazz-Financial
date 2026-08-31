<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit row for BorrowingController::renew() — a borrowing rolled over for
 * another term when it matured. The borrowing row itself is updated in place
 * (new maturity_date / term / rate); this records what it was before each
 * roll-over. No cash moves on a renewal — any interest cleared at roll-over
 * time is a separate borrowing_transactions InterestPayment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('borrowing_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrowing_id')->constrained('borrowings')->cascadeOnDelete();
            $table->date('renewed_on');
            $table->date('previous_maturity_date')->nullable();
            $table->date('new_maturity_date');
            $table->unsignedSmallInteger('previous_term_months')->nullable();
            $table->unsignedSmallInteger('new_term_months')->nullable();
            $table->decimal('previous_interest_rate_percent', 6, 3)->unsigned()->nullable();
            $table->decimal('new_interest_rate_percent', 6, 3)->unsigned()->nullable();
            $table->decimal('interest_paid_amount', 15, 2)->unsigned()->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('borrowing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrowing_renewals');
    }
};
