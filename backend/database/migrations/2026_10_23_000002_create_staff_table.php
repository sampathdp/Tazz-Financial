<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff register: one row per staff member with a system staff code (STF-0001...).
 * A login (users row) is optional and linked through staff.user_id. Existing users are
 * backfilled as staff in id order, and the money tables that name a person (commissions,
 * expenses, vouchers) get a nullable staff_id next to their existing columns.
 */
return new class extends Migration
{
    private const PAYEE_TABLES = ['expenses', 'payment_vouchers', 'petty_cash_vouchers'];

    public function up(): void
    {
        if (!Schema::hasTable('staff')) {
            Schema::create('staff', function (Blueprint $table) {
                $table->id();
                $table->string('staff_code', 24)->unique();
                $table->string('name', 150);
                $table->string('nic_no', 20)->nullable()->unique();
                $table->string('phone', 20)->nullable();
                $table->string('email', 255)->nullable()->unique();
                $table->string('designation', 100)->nullable();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
                $table->enum('status', ['Active', 'Inactive'])->default('Active');
                $table->timestamps();
                $table->index('name');
                $table->index('phone');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE finance_requests MODIFY payee_type ENUM('Customer','Vendor','Staff','User','Other') NULL");
        }

        $this->backfillFromUsers();

        foreach (array_merge(self::PAYEE_TABLES, ['sales_executive_commissions']) as $name) {
            if (Schema::hasTable($name) && !Schema::hasColumn($name, 'staff_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
                });
            }
        }

        DB::statement('UPDATE sales_executive_commissions c JOIN staff s ON s.user_id = c.sales_executive_id SET c.staff_id = s.id WHERE c.staff_id IS NULL');
    }

    public function down(): void
    {
        foreach (array_merge(self::PAYEE_TABLES, ['sales_executive_commissions']) as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'staff_id')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('staff_id');
                });
            }
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE finance_requests SET payee_type = 'Other' WHERE payee_type = 'Staff'");
            DB::statement("ALTER TABLE finance_requests MODIFY payee_type ENUM('Customer','Vendor','User','Other') NULL");
        }
        Schema::dropIfExists('staff');
    }

    private function backfillFromUsers(): void
    {
        $linked = DB::table('staff')->whereNotNull('user_id')->pluck('user_id')->all();
        $users = DB::table('users')->whereNotIn('id', $linked)->orderBy('id')->get(['id', 'name', 'email', 'branch_id', 'status']);

        foreach ($users as $u) {
            $id = DB::table('staff')->insertGetId([
                'staff_code' => 'TMP-' . $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'branch_id' => $u->branch_id,
                'user_id' => $u->id,
                'status' => $u->status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('staff')->where('id', $id)->update(['staff_code' => sprintf('STF-%04d', $id)]);
        }
    }
};
