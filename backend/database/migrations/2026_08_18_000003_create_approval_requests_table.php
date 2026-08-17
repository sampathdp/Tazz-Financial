<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One shared table for every "submit a request, needs N approvals before it
 * executes" workflow in the system — loan cancellation, payment-receipt
 * cancellation, and whatever approval_types row a future feature adds.
 * approval_type_id is the discriminator (which kind of request this is);
 * subject_id is the id of the row it's about (a credit_facilities.id for
 * loan_cancel, a facility_payments.id for payment_void, ...). subject_id
 * deliberately has no foreign key, since which table it points into varies
 * by approval_type — the controller for that type is responsible for
 * resolving and validating it (see LoanController::cancelRequest() /
 * LoanPaymentController::voidRequest()). This is the same "add a new type
 * without a new migration" property approval_types itself has: a brand new
 * approval point reuses this table, it never needs its own.
 *
 * required_approvals is snapshotted from approval_types at request time, so
 * a later change to that type's setting doesn't retroactively change what an
 * in-flight request needs. Capped at two approval slots (rather than a child
 * approvals table) because a type only ever requires 1 or 2 — see that
 * column's docblock on approval_types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('requested_by')->constrained('users');
            $table->dateTime('requested_at');
            $table->string('reason', 255)->nullable();
            $table->unsignedTinyInteger('required_approvals')->default(1);
            $table->enum('status', ['PendingApproval', 'Approved', 'Rejected'])->default('PendingApproval');
            $table->foreignId('first_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('first_approved_at')->nullable();
            $table->foreignId('second_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('second_approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['approval_type_id', 'subject_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
