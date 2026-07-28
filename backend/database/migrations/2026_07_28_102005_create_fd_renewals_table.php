<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('old_fixed_deposit_id')->constrained('fixed_deposits')->restrictOnDelete();
            $table->foreignId('new_fixed_deposit_id')->constrained('fixed_deposits')->restrictOnDelete();
            $table->decimal('old_principal', 15, 2);
            $table->decimal('additional_deposit_amount', 15, 2)->default(0);
            $table->decimal('new_principal', 15, 2);
            // The capital-enhancement deposit, if any — its own printable Deposit Slip.
            $table->foreignId('additional_deposit_id')->nullable()->constrained('fd_deposits')->nullOnDelete();
            $table->unsignedSmallInteger('new_term_months');
            $table->date('renewed_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_renewals');
    }
};
