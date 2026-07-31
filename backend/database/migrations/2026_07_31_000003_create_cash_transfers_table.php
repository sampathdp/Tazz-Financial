<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCashTransfersTable extends Migration
{
    public function up()
    {
        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->foreignId('to_cash_account_id')->constrained('cash_accounts')->restrictOnDelete();
            $table->date('transfer_date');
            $table->decimal('amount', 15, 2);
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('Pending'); // Pending | Completed | Rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('cash_transfers');
    }
}
