<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBranchesTable extends Migration
{
    public function up()
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('code', 16)->unique();
            $table->string('address', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 190)->nullable();
            $table->boolean('is_head_office')->default(false);
            $table->string('status', 16)->default('Active');
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('branches');
    }
}
