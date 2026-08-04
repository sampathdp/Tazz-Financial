<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merges loan_schedules + speed_draft_schedules. Two renames worth calling
 * out:
 *  - speed_draft_schedules' `capital_due` (populated only on the final/
 *    maturity row — a Speed Draft's capital is never amortized into
 *    installments, only its interest is) maps onto `principal_due` here,
 *    the same column loan installments already use for their amortized
 *    principal portion. Every non-final Speed Draft row simply has
 *    principal_due = 0.
 *  - `penalty_due` exists on this merged table but is never written to a
 *    nonzero value by either product today: Loan's OD/default-charge figure
 *    is deliberately never persisted (see App\Support\LoanSummary —
 *    recalculated live on every view AND every real payment so display and
 *    collection can never disagree), and Speed Draft has no penalty concept
 *    at all. It's kept as a real column (not dropped) because the CHECK
 *    below needs it to express total_due generally, and because a stored
 *    penalty is the natural next step once one product needs it — but nothing
 *    should assume it's populated today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->smallInteger('installment_no')->unsigned();
            $table->date('due_date');
            $table->decimal('principal_due', 15, 2)->unsigned()->default(0);
            $table->decimal('interest_due', 15, 2)->unsigned()->default(0);
            $table->decimal('penalty_due', 15, 2)->unsigned()->default(0);
            $table->decimal('total_due', 15, 2)->unsigned();
            $table->decimal('paid_amount', 15, 2)->unsigned()->default(0);
            $table->dateTime('paid_at')->nullable();
            $table->enum('status', ['Pending', 'Partial', 'Paid'])->default('Pending');
            $table->timestamps();
            $table->unique(['credit_facility_id', 'installment_no']);
            $table->index(['status', 'due_date']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `facility_schedules` ADD CONSTRAINT `chk_fs_total_due`
            CHECK (`total_due` = `principal_due` + `interest_due` + `penalty_due`)
        SQL);
        DB::statement('ALTER TABLE `facility_schedules` ADD CONSTRAINT `chk_fs_paid_amount` CHECK (`paid_amount` <= `total_due`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_schedules');
    }
};
