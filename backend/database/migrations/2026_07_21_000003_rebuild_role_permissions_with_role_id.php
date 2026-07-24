<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original role_permissions table (string `role` column) was created but
 * never written to by any controller — role defaults were hardcoded in PHP
 * instead. Safe to drop and recreate keyed to the new roles table now that
 * role-based permissions are actually backed by data.
 */
class RebuildRolePermissionsWithRoleId extends Migration
{
    public function up()
    {
        Schema::dropIfExists('role_permissions');

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('module', 64);
            $table->string('action', 16); // View | Create | Edit | Delete | Approve | Export
            $table->timestamps();
            $table->unique(['role_id', 'module', 'action'], 'role_perm_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('role_permissions');

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 32);
            $table->string('module', 64);
            $table->string('action', 16);
            $table->timestamps();
            $table->unique(['role', 'module', 'action'], 'role_perm_unique');
            $table->index(['role']);
        });
    }
}
