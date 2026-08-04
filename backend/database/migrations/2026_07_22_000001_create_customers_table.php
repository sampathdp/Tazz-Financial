<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidates what was originally four migrations (create, add
 * is_guarantor, add title/surname/name_in_full/initials, make nic_no's
 * uniqueness soft-delete-safe) into the final shape directly.
 *
 * A "guarantor" is not a separate person-type — it's a customer acting in a
 * different capacity for a specific loan (see loan_guarantors, in the
 * Credit Facilities domain) — one KYC record per person, no duplicate NIC/
 * phone/address/employment data entry.
 *
 * Employer and business columns move to customer_employment_details and
 * customer_business_details (next two migrations) — most customers need
 * neither cluster (occupation_type picks at most one), so keeping both
 * directly on this table meant ~10 columns that were NULL for most rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->char('public_uuid', 36);
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('code', 24)->unique();
            $table->enum('title', ['Mr.', 'Mrs.', 'Miss', 'Ms.', 'Dr.', 'Rev.'])->nullable();
            // Derived, not entered directly — Title + Initials + Surname
            // (e.g. "Mr. J.K. Perera") is what every loan/list screen
            // displays; see Customer::booted().
            $table->string('full_name', 191);
            // The expanded given name(s) as printed on formal KYC documents
            // — genuinely distinct from full_name (which stays the short
            // "Title + Initials + Surname" display form), not a duplicate.
            $table->string('name_denoted_by_initials', 191)->nullable();
            $table->string('surname', 100)->nullable();
            $table->string('initials', 20)->nullable();
            $table->string('nic_no', 15)->nullable();
            $table->string('passport_no', 20)->nullable();
            $table->string('driving_license_no', 20)->nullable();
            $table->date('dob')->nullable();
            $table->enum('gender', ['Male', 'Female', 'Other'])->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('alt_phone', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('address', 255)->nullable();
            // Employed | SelfEmployed | BusinessOwner | Unemployed | Retired | Student | Other
            $table->enum('occupation_type', ['Employed', 'SelfEmployed', 'BusinessOwner', 'Unemployed', 'Retired', 'Student', 'Other'])->nullable();
            $table->decimal('monthly_income', 15, 2)->unsigned()->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->enum('status', ['Active', 'Inactive', 'Blacklisted'])->default('Active');
            // A label, not a restriction — any customer can be picked as a
            // loan guarantor regardless of this flag. It just tags people
            // registered primarily to guarantee someone else's loan, so
            // staff can spot/filter them in the list.
            $table->boolean('is_guarantor')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('public_uuid');
            $table->index('branch_id');
            $table->index('status');
        });

        // nic_no's uniqueness is scoped around soft-deletes via a generated
        // column that resolves to NULL whenever the row is trashed (or
        // nic_no itself is null) — MySQL/MariaDB never enforce uniqueness
        // across NULLs, so a trashed row simply stops competing for that
        // NIC. Without this, deleting a duplicate/mistaken entry then
        // re-registering the correct customer under the same NIC would fail
        // permanently, with no recovery path. See CustomerController for
        // the matching validation-layer fix (Rule::unique alone doesn't
        // know about this).
        DB::statement("ALTER TABLE `customers` ADD COLUMN `nic_no_active` VARCHAR(15) GENERATED ALWAYS AS (CASE WHEN `deleted_at` IS NULL THEN `nic_no` ELSE NULL END) VIRTUAL");
        Schema::table('customers', function (Blueprint $table) {
            $table->unique('nic_no_active');
        });

        DB::statement('ALTER TABLE `customers` ADD FULLTEXT `ftx_customers_name` (`full_name`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
