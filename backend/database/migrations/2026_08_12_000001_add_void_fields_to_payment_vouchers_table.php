<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment Vouchers now get a real screen (view + void) instead of only ever
 * being shown inline on the Expenses list — see PaymentVoucherController.
 * 'Voided' was already reserved on the status enum when this table was
 * created; these are the audit columns that go with it, matching the
 * void_reason/voided_by/voided_at shape used by facility_payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->string('void_reason', 255)->nullable()->after('status');
            $table->foreignId('voided_by')->nullable()->after('void_reason')->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['void_reason', 'voided_at']);
        });
    }
};
