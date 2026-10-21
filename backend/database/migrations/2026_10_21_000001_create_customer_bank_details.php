<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where we send a customer's money: loan disbursements and FD interest/maturity
 * transfers go to one of these accounts. The holder name, branch and account
 * number are encrypted at rest (Eloquent `encrypted` cast, hence TEXT columns);
 * only the bank FK stays queryable. Loans (credit_facilities) and Fixed Deposits
 * point at the account chosen when they were created. The columns are nullable
 * only so pre-existing rows stay valid - the API requires one for every new facility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_bank_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('bank_id')->constrained('banks');
            $table->text('account_holder');
            $table->text('bank_branch')->nullable();
            $table->text('account_number');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->foreignId('customer_bank_detail_id')->nullable()->constrained('customer_bank_details')->nullOnDelete();
        });
        Schema::table('fixed_deposits', function (Blueprint $table) {
            $table->foreignId('customer_bank_detail_id')->nullable()->constrained('customer_bank_details')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fixed_deposits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_bank_detail_id');
        });
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_bank_detail_id');
        });
        Schema::dropIfExists('customer_bank_details');
    }
};
