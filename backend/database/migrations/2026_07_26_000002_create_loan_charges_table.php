<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-off, non-rental charges (Rental itself and Default Interest are
        // derived from loan_schedules/loan_payment_allocations, not stored here).
        Schema::create('loan_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('charge_type', 32); // Document | Legal | Valuation | Other
            $table->decimal('due_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->date('charged_at');
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['loan_id', 'charge_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_charges');
    }
};
