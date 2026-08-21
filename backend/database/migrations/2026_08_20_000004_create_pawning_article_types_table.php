<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Article master for pawned jewellery — replaces a free-text "article"
 * field with a real lookup, same role pawning_article_types plays for the
 * per-item appraisal (facility_collateral_pawn_details.article_type_id).
 * Seeded with the common article categories gold-loan branches actually
 * appraise; branches can add more without a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pawning_article_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('code', 16)->unique();
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->smallInteger('sort_order')->unsigned()->default(0);
            $table->timestamps();
        });

        $now = now();
        $types = [
            ['name' => 'Chain', 'code' => 'CHAIN'],
            ['name' => 'Necklace', 'code' => 'NECK'],
            ['name' => 'Ring', 'code' => 'RING'],
            ['name' => 'Bangle', 'code' => 'BANGLE'],
            ['name' => 'Bracelet', 'code' => 'BRACE'],
            ['name' => 'Ear Stud', 'code' => 'ESTUD'],
            ['name' => 'Pendant', 'code' => 'PEND'],
            ['name' => 'Coin', 'code' => 'COIN'],
            ['name' => 'Bar', 'code' => 'BAR'],
            ['name' => 'Anklet', 'code' => 'ANKLET'],
            ['name' => 'Other', 'code' => 'OTHER'],
        ];
        foreach ($types as $i => $type) {
            DB::table('pawning_article_types')->insert($type + ['sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pawning_article_types');
    }
};
