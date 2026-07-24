<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->string('status', 16)->default('Active')->after('amount'); // Active | Voided
            $table->string('void_reason', 255)->nullable()->after('notes');
            $table->foreignId('voided_by')->nullable()->after('void_reason')->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['status', 'void_reason', 'voided_at']);
        });
    }
};
