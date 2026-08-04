<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('code', 24)->unique();
            $table->enum('interest_method', ['monthly', 'maturity']);
            $table->decimal('annual_rate_percent', 6, 3)->unsigned();
            // Auto-derived (annual/12) unless rate_is_manual overrides it.
            $table->decimal('monthly_rate_percent', 6, 3)->unsigned()->nullable();
            $table->boolean('rate_is_manual')->default(false);
            // e.g. [3,6,9,12] — months a customer may choose at FD creation.
            // MariaDB has no native JSON type (LONGTEXT alias) — CHECK
            // json_valid() gets the same validity enforcement explicitly.
            $table->longText('allowed_terms_months')->charset('utf8mb4')->collation('utf8mb4_bin');
            $table->boolean('allow_custom_term')->default(false);
            $table->decimal('min_deposit_amount', 15, 2)->unsigned();
            $table->decimal('max_deposit_amount', 15, 2)->unsigned()->nullable();
            // Template for this product's generated FD numbers, e.g. "{BRANCH}-FD-{CODE}-{YEAR:2}-{SEQ:6}". Null = system default.
            $table->string('reference_format', 64)->nullable();
            $table->smallInteger('sort_order')->unsigned()->default(0);
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE `funding_products` ADD CONSTRAINT `chk_funding_products_terms_json` CHECK (JSON_VALID(`allowed_terms_months`))');
        DB::statement(<<<'SQL'
            ALTER TABLE `funding_products` ADD CONSTRAINT `chk_funding_products_deposit_range`
            CHECK (`max_deposit_amount` IS NULL OR `max_deposit_amount` >= `min_deposit_amount`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_products');
    }
};
