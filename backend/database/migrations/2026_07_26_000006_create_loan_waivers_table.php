<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Covers both the legacy "W-Off Set Plan" and "Waved Off Details" screens:
        // a single record of an amount waived off a loan, of a given kind.
        Schema::create('loan_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->string('waiver_type', 32); // Interest | Penalty | Charge | Principal
            $table->decimal('amount', 15, 2);
            $table->string('reason', 255)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('waived_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_waivers');
    }
};
