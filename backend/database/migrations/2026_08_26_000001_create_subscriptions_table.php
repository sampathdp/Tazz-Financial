<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks recurring subscriptions (domain registrations, hosting, software
 * licenses) and legal/compliance documents that carry a hard expiry date.
 * `reminder_days_before` drives the "due soon" notification query
 * (Subscription::scopeDueSoon()) surfaced through the existing
 * NotificationBell — see SubscriptionController::dueSoon().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->enum('type', ['Domain', 'Hosting', 'Software License', 'Legal Document', 'Insurance', 'Other'])->default('Other');
            $table->string('provider', 150)->nullable();
            $table->string('reference_no', 100)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('currency', 8)->default('LKR');
            $table->date('start_date')->nullable();
            $table->date('expiry_date');
            $table->smallInteger('reminder_days_before')->unsigned()->default(30);
            $table->enum('status', ['Active', 'Inactive', 'Cancelled'])->default('Active');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
