<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch interest-posting run over a period. Created as a Draft (interest is
 * computed and previewed per account), then Posted once approved
 * (savings_interest_run approval code): each account gets an InterestPosting
 * savings_transactions row (Dr 5120 / Cr 2050, GL-only — no cash) and its
 * last_interest_posted_on advances. Voiding reverses the GL and deletes the
 * per-account transactions.
 *
 * The per-account breakdown is the set of savings_transactions rows carrying
 * this run's interest_run_id — no separate detail table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_interest_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_number', 24)->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->enum('status', ['Draft', 'Posted', 'Voided'])->default('Draft');
            $table->unsignedInteger('account_count')->default(0);
            $table->decimal('total_interest', 15, 2)->unsigned()->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
            $table->index(['period_from', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_interest_runs');
    }
};
