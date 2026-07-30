<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Banks get their own first-class table instead of living under the generic
 * master_categories lookup (type=bank) — a dedicated entity leaves room for
 * bank-specific fields later (SWIFT code, etc.) without a `type` discriminator
 * in the way, and gives it proper referential integrity as more screens start
 * pointing at it. Carries forward whatever was already seeded under
 * master_categories(type=bank) — see 2026_07_30_000002_seed_bank_master_categories
 * — then removes those rows so the same bank doesn't exist in two places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('code', 32)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('Active');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $existing = DB::table('master_categories')->where('type', 'bank')->orderBy('sort_order')->orderBy('name')->get();
        foreach ($existing as $row) {
            DB::table('banks')->insert([
                'name' => $row->name,
                'code' => $row->code,
                'description' => $row->description,
                'status' => $row->status,
                'sort_order' => $row->sort_order,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('master_categories')->where('type', 'bank')->delete();
    }

    public function down(): void
    {
        // Data is not carried back to master_categories — banks was additive
        // (the old bank_account_type rows it replaced are long gone), so the
        // only meaningful rollback is dropping the table.
        Schema::dropIfExists('banks');
    }
};
