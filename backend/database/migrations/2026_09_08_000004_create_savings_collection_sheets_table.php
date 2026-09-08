<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A field-collection sheet — the daily list of savings deposits a collector
 * brings in from the round. Built up line by line (status Open), then Posted
 * in one action: each line becomes a Cash-method Deposit on its account
 * (SavingsAccountService) and the sheet's cash lands in the branch till in a
 * single GL/cashbook movement. Same maker step as a manual cash receipt book,
 * batched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_collection_sheets', function (Blueprint $table) {
            $table->id();
            $table->string('sheet_number', 24)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('collector_id')->constrained('users');
            $table->date('collection_date');
            $table->enum('status', ['Open', 'Posted', 'Cancelled'])->default('Open');
            $table->decimal('total_amount', 15, 2)->unsigned()->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'collection_date']);
            $table->index('status');
            $table->index('collector_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_collection_sheets');
    }
};
