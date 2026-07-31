<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The generic master_categories lookup is retired now that every list it
 * served (bank, expense_category) has been promoted to its own dedicated
 * table — see 2026_07_30_000003_create_banks_table and
 * 2026_08_01_000004_create_expense_categories_table, both of which already
 * carried the data forward. Nothing reads from master_categories anymore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('master_categories');
    }

    public function down(): void
    {
        Schema::create('master_categories', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->string('name', 128);
            $table->string('code', 32)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('status', 16)->default('Active');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['type', 'name']);
            $table->index(['type', 'status']);
        });
    }
};
