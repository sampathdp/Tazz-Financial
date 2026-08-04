<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renamed from loan_post_dated_cheques. bank_id replaces the free-text
 * bank_name — same rationale as facility_payments.bank_id. A cheque number
 * is only unique per issuing bank (two different banks could coincidentally
 * both have a cheque #123456), so the uniqueness constraint is on the pair,
 * not cheque_no alone — the original schema had no uniqueness check on
 * cheque_no at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_post_dated_cheques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('cheque_no', 20);
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->date('cheque_date');
            $table->decimal('amount', 15, 2)->unsigned();
            $table->enum('status', ['Pending', 'Handed', 'Realized', 'Bounced', 'Returned'])->default('Pending');
            $table->date('handed_at')->nullable();
            $table->date('realized_at')->nullable();
            $table->foreignId('facility_payment_id')->nullable()->constrained('facility_payments')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cheque_no', 'bank_id']);
            $table->index(['credit_facility_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_post_dated_cheques');
    }
};
