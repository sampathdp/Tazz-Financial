<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail of actual provision Post/Reverse actions — one row per booking,
 * not per recompute (classification is refreshed constantly via cache
 * columns on credit_facilities; this table only records the moments someone
 * actually moved money between 5300/1190). Styled on facility_status_events:
 * a cache column can only ever show the CURRENT posted amount, not who
 * booked what and when, which is what this table is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_provisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->enum('action', ['Post', 'Reverse']);
            $table->decimal('overdue_installments_at_action', 8, 2);
            $table->unsignedTinyInteger('slab_pct');
            $table->decimal('base_amount', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['credit_facility_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_provisions');
    }
};
