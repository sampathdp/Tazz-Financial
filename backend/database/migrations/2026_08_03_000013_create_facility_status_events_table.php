<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces loans' four boolean+timestamp column pairs (is_terminated/
 * terminated_at, is_legal/legal_at, seizing_order_issued/seizing_order_at,
 * is_repossessed/repossessed_at) with one event log. That shape could only
 * ever record the LATEST occurrence of each — if a seizing order were ever
 * issued, then withdrawn, then reissued, the old columns had no way to
 * represent that history, only the current flag. An event log can.
 * loan-only today (SpeedDraft never had these fields), but shared on the
 * merged table for whenever a Speed Draft needs the same recovery workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->enum('event_type', ['Terminated', 'Legal', 'SeizingOrderIssued', 'Repossessed']);
            $table->date('event_date');
            $table->string('notes', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['credit_facility_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_status_events');
    }
};
