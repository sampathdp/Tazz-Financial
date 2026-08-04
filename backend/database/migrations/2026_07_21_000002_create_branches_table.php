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
            $table->foreignId('parent_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('name', 128);
            $table->string('code', 16)->unique();
            $table->enum('branch_type', ['Branch', 'Center'])->default('Branch');
            $table->boolean('is_head_office')->default(false);
            $table->string('address', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->enum('status', ['Active', 'Inactive'])->default('Active');
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('branches');
    }
}
