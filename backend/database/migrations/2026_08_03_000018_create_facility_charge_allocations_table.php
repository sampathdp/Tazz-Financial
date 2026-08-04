<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Renamed from loan_charge_allocations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_charge_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_payment_id')->constrained('facility_payments')->cascadeOnDelete();
            $table->foreignId('facility_charge_id')->constrained('facility_charges');
            $table->decimal('amount_applied', 15, 2)->unsigned()->default(0);
            $table->timestamps();
            $table->index('facility_payment_id');
            $table->index('facility_charge_id');
        });

        DB::statement('ALTER TABLE `facility_charge_allocations` ADD CONSTRAINT `chk_fca_amount` CHECK (`amount_applied` > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_charge_allocations');
    }
};
