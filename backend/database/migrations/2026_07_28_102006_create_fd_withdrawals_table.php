
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fd_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_deposit_id')->unique()->constrained('fixed_deposits')->restrictOnDelete();
            $table->string('voucher_no', 64)->unique();
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('interest_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->string('payment_method', 16); // Cash | Cheque | BankTransfer
            $table->string('reference_no', 64)->nullable();
            $table->text('notes')->nullable();
            $table->date('closed_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fd_withdrawals');
    }
};
