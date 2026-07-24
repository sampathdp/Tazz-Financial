<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRolesTable extends Migration
{
    public function up()
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('slug', 64)->unique();
            $table->string('description', 255)->nullable();
            // The 'admin' role: bypasses the permission matrix entirely and cannot be edited/deleted.
            $table->boolean('is_system')->default(false);
            // Roles that are not confined to their user's branch (e.g. admin, auditor, head-office staff).
            $table->boolean('can_view_all_branches')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('roles');
    }
}
