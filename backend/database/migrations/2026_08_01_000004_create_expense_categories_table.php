<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `expense_categories` already exists in production (see schema.sql, spec
 * 3.9 Expense Management) — it predates this migration and was never tracked
 * by Laravel's migrations table, which is why the Masters screen (previously
 * backed by the generic master_categories(type=expense_category) lookup)
 * couldn't just point at it as-is. This adds the `description`/`sort_order`
 * columns the Masters screen needs (matching the `banks` table shape) and
 * carries forward whatever was seeded under master_categories before
 * removing those rows, so the same category doesn't exist in two places.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('expense_categories')) {
            Schema::create('expense_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 128);
                $table->string('code', 32)->unique();
                $table->unsignedBigInteger('account_id')->nullable();
                $table->string('status', 16)->default('Active');
                $table->timestamps();
            });
        }

        Schema::table('expense_categories', function (Blueprint $table) {
            if (!Schema::hasColumn('expense_categories', 'description')) {
                $table->string('description', 255)->nullable()->after('code');
            }
            if (!Schema::hasColumn('expense_categories', 'sort_order')) {
                $table->integer('sort_order')->default(0)->after('status');
            }
        });

        if (!Schema::hasTable('master_categories')) {
            return;
        }

        $now = now();
        $existing = DB::table('master_categories')->where('type', 'expense_category')->orderBy('sort_order')->orderBy('name')->get();
        foreach ($existing as $row) {
            // The old lookup allowed a blank code; expense_categories requires
            // a unique one, so backfill anything blank with a synthetic code.
            $code = $row->code ?: ('EXP-' . $row->id);
            if (DB::table('expense_categories')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('expense_categories')->insert([
                'name' => $row->name,
                'code' => $code,
                'description' => $row->description,
                'status' => $row->status,
                'sort_order' => $row->sort_order,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('master_categories')->where('type', 'expense_category')->delete();
    }

    public function down(): void
    {
        // The table predates this migration, so only undo what this
        // migration itself added — never drop expense_categories here.
        Schema::table('expense_categories', function (Blueprint $table) {
            if (Schema::hasColumn('expense_categories', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
            if (Schema::hasColumn('expense_categories', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
