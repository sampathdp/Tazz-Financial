<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Renamed from loan_charges — Speed Drafts have no charges today, but the table is shared for whenever that changes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches');
            $table->enum('charge_type', ['Document', 'Legal', 'Valuation', 'Other']);
            $table->decimal('due_amount', 15, 2)->unsigned();
            $table->decimal('paid_amount', 15, 2)->unsigned()->default(0);
            $table->date('charged_at');
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['credit_facility_id', 'charge_type']);
        });

        DB::statement('ALTER TABLE `facility_charges` ADD CONSTRAINT `chk_fc_paid_le_due` CHECK (`paid_amount` <= `due_amount`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_charges');
    }
};
