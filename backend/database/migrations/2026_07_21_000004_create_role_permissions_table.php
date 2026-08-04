<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A role's granted permissions — now a pointer to one `permissions` row
 * instead of re-typing its module/action pair directly (that shape let the
 * same "Loan Applications" + "Approve" string exist as independent,
 * unconstrained copies here and in user_permission_overrides, with nothing
 * to catch one drifting from the other).
 *
 * No updated_at: Permissions::saveForRole() always deletes and reinserts a
 * role's full grant set, so a row is only ever created or deleted, never
 * edited in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
