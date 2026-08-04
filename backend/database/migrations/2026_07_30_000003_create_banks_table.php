<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Banks as a first-class, referenced lookup — the single source of truth
 * every `bank_name` free-text column elsewhere (bank_accounts, loan/speed
 * draft payments, post-dated cheques, FD deposits) now points at via
 * bank_id, instead of each one repeating the bank's name as an unconstrained
 * string. Seeds a starter set directly (no longer staged through the
 * now-removed master_categories(type=bank) detour — that table exists only
 * for expense_categories now, unrelated to this one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128)->unique();
            $table->string('short_code', 32)->nullable();
            $table->string('description', 255)->nullable();
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->smallInteger('sort_order')->unsigned()->default(0);
            $table->timestamps();
        });

        $now = now();
        $banks = [
            ['name' => 'Bank of Ceylon', 'short_code' => 'BOC'],
            ['name' => "People's Bank", 'short_code' => 'PB'],
            ['name' => 'Commercial Bank of Ceylon PLC', 'short_code' => 'COMB'],
            ['name' => 'Hatton National Bank PLC', 'short_code' => 'HNB'],
            ['name' => 'Sampath Bank PLC', 'short_code' => 'SAMP'],
            ['name' => 'Seylan Bank PLC', 'short_code' => 'SEY'],
            ['name' => 'National Savings Bank', 'short_code' => 'NSB'],
            ['name' => 'National Development Bank PLC', 'short_code' => 'NDB'],
            ['name' => 'DFCC Bank PLC', 'short_code' => 'DFCC'],
            ['name' => 'Nations Trust Bank PLC', 'short_code' => 'NTB'],
            ['name' => 'Pan Asia Banking Corporation PLC', 'short_code' => 'PABC'],
            ['name' => 'Union Bank of Colombo PLC', 'short_code' => 'UBC'],
            ['name' => 'Cargills Bank Ltd', 'short_code' => 'CARG'],
        ];
        foreach ($banks as $i => $bank) {
            DB::table('banks')->insert($bank + ['sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
