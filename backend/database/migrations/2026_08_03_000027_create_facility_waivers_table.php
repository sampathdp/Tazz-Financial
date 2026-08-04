<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Renamed from loan_waivers — Speed Drafts have no waivers today, but the table is shared for whenever that changes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->enum('waiver_type', ['Interest', 'Penalty', 'Charge', 'Principal']);
            $table->decimal('amount', 15, 2)->unsigned();
            $table->string('reason', 255)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('waived_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('credit_facility_id');
        });

        DB::statement('ALTER TABLE facility_waivers ADD CONSTRAINT chk_fw_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_waivers');
    }
};
