<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            // The date the cheque actually cleared at the bank (a.k.a. "check-in
            // date") — set when clearCheque() marks it Realized. Distinct from
            // cheque_date (the date printed on the cheque) and paid_at (when the
            // payment was first recorded). This is the date the amount is
            // considered to have landed in the loan for penalty/OD and
            // installment-settlement purposes.
            $table->date('realized_at')->nullable()->after('cheque_date');
        });

        Schema::table('loan_post_dated_cheques', function (Blueprint $table) {
            $table->date('realized_at')->nullable()->after('handed_at');
            // Links to the LoanPayment created when this cheque is realized, so
            // the amount actually reduces the loan balance (previously a no-op)
            // and the cross-loan cheque list can avoid listing it twice.
            $table->foreignId('loan_payment_id')->nullable()->after('realized_at')
                ->constrained('loan_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loan_post_dated_cheques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loan_payment_id');
            $table->dropColumn('realized_at');
        });

        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropColumn('realized_at');
        });
    }
};
