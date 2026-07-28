<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMasterCategoriesTable extends Migration
{
    public function up()
    {
        Schema::create('master_categories', function (Blueprint $table) {
            $table->id();
            // Discriminator for which lookup list this row belongs to (e.g.
            // 'expense_category', 'bank_account_type'). New lookup types are
            // added by whitelisting a key in MasterCategoryController::TYPES —
            // no migration needed.
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

    public function down()
    {
        Schema::dropIfExists('master_categories');
    }
}
