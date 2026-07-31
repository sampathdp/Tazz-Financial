<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_types', function (Blueprint $table) {
            $table->string('commission_type', 16)->default('fixed')->after('processing_fee_percent'); // fixed | percentage
            $table->decimal('commission_value', 15, 2)->default(0)->after('commission_type'); // amount, or percent of disbursed_amount
            $table->unsignedSmallInteger('min_paid_installments')->default(0)->after('commission_value');
        });
    }

    public function down(): void
    {
        Schema::table('loan_types', function (Blueprint $table) {
            $table->dropColumn(['commission_type', 'commission_value', 'min_paid_installments']);
        });
    }
};
