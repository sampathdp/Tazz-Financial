<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (module, action) cell in app\Support\Permissions'
 * MODULES x ACTIONS grid — the single source of truth that role_permissions
 * and user_permission_overrides now point at, instead of each independently
 * re-typing the same module/action string pair (see those two tables'
 * migrations). `module` stays a plain string rather than an enum: the grid's
 * module list is a PHP constant that grows with every new screen shipped
 * ("add new entries here when new modules ship" — Permissions::MODULES),
 * and an enum would force a migration on each addition. `action` is safely
 * an enum: Permissions::ACTIONS is a fixed, rarely-changing 6-item list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('module', 64);
            $table->enum('action', ['View', 'Create', 'Edit', 'Delete', 'Approve', 'Export']);
            $table->timestamp('created_at')->nullable();
            $table->unique(['module', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
