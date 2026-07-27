<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBankAccountsTable extends Migration
{
    public function up()
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name', 128);
            $table->string('bank_name', 128);
            $table->string('branch_name', 128)->nullable();
            $table->string('account_number', 64)->unique();
            $table->string('account_type', 20)->default('Savings');
            $table->string('currency', 8)->default('LKR');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->string('status', 16)->default('Active');
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_accounts');
    }
}
