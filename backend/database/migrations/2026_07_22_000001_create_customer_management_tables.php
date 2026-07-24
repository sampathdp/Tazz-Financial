<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomerManagementTables extends Migration
{
    public function up()
    {
        // A "guarantor" is not a separate person-type — it's a customer acting
        // in a different capacity for a specific loan (see loan_guarantors,
        // added with the Loan Management migration). One KYC record per
        // person, no duplicate NIC/phone/address/employment data entry.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('code', 32)->unique();
            $table->string('full_name', 191);
            $table->string('nic_no', 32)->nullable()->unique();
            $table->string('passport_no', 32)->nullable();
            $table->string('driving_license_no', 32)->nullable();
            $table->date('dob')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('alt_phone', 32)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('address')->nullable();

            // Employed | SelfEmployed | BusinessOwner | Unemployed | Retired | Student | Other
            $table->string('occupation_type', 20)->nullable();
            // populated when occupation_type = Employed
            $table->string('employer_name', 191)->nullable();
            $table->string('job_title', 128)->nullable();
            $table->string('employer_address', 255)->nullable();
            $table->string('employer_phone', 32)->nullable();
            $table->date('employment_start_date')->nullable();
            // populated when occupation_type = SelfEmployed | BusinessOwner
            $table->string('business_name', 191)->nullable();
            $table->string('business_reg_no', 64)->nullable();
            $table->string('business_type', 128)->nullable();
            $table->string('business_address', 255)->nullable();
            $table->string('business_phone', 32)->nullable();

            $table->decimal('monthly_income', 15, 2)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->string('status', 20)->default('Active'); // Active | Blacklisted | Inactive
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
        });

        Schema::create('customer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 32); // NIC | Passport | DrivingLicense | UtilityBill | Photo | Other
            $table->string('file_path', 255);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('customer_documents');
        Schema::dropIfExists('customers');
    }
}
