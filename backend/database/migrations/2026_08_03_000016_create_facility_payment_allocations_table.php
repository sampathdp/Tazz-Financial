<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merges loan_payment_allocations + speed_draft_payment_allocations.
 * speed_draft_payment_allocations' `capital_applied` is renamed
 * `principal_applied` to match the column loan allocations already used for
 * the same concept. `penalty_applied` is loan-only in practice (Speed Draft
 * has no penalty calculation at all — see facility_schedules' docblock) and
 * is simply always 0 for a Speed-Draft-originated row.
 * facility_schedule_id is nullable because a Speed Draft capital reduction
 * isn't tied to any specific installment (it reduces the facility's
 * outstanding balance directly, not a scheduled due amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_payment_id')->constrained('facility_payments')->cascadeOnDelete();
            $table->foreignId('facility_schedule_id')->nullable()->constrained('facility_schedules');
            $table->decimal('principal_applied', 15, 2)->unsigned()->default(0);
            $table->decimal('interest_applied', 15, 2)->unsigned()->default(0);
            $table->decimal('penalty_applied', 15, 2)->unsigned()->default(0);
            $table->timestamps();
            $table->index('facility_payment_id');
            $table->index('facility_schedule_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `facility_payment_allocations` ADD CONSTRAINT `chk_fpa_nonzero`
            CHECK (`principal_applied` + `interest_applied` + `penalty_applied` > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_payment_allocations');
    }
};
