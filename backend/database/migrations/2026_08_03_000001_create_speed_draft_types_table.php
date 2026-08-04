<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speed_draft_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('code', 24)->unique();
            $table->enum('category', ['Loan', 'Mortgage', 'GoldPawning', 'Lease']);
            $table->string('reference_format', 64)->nullable();
            $table->decimal('interest_rate_percent', 6, 3)->unsigned();
            $table->enum('rate_period', ['monthly', 'annual'])->default('monthly');
            $table->decimal('min_amount', 15, 2)->unsigned()->nullable();
            $table->decimal('max_amount', 15, 2)->unsigned()->nullable();
            $table->smallInteger('min_period_months')->unsigned()->nullable();
            $table->smallInteger('max_period_months')->unsigned()->nullable();
            $table->smallInteger('grace_period_days')->unsigned()->default(5);
            $table->boolean('requires_collateral')->default(false);
            $table->smallInteger('sort_order')->unsigned()->default(0);
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE `speed_draft_types` ADD CONSTRAINT `chk_sdt_amount_range`
            CHECK (`max_amount` IS NULL OR `min_amount` IS NULL OR `max_amount` >= `min_amount`)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('speed_draft_types');
    }
};
