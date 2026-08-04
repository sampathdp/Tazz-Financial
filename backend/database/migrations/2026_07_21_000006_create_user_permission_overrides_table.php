<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renamed from user_permissions to say what it actually is: a per-user
 * exception layered on top of the role grid (Permissions::gridForUser layers
 * this over gridForRole). Same permission_id-lookup change as
 * role_permissions. Keeps updated_at, unlike role_permissions — the
 * `allowed` flag is genuinely edited in place on an existing row
 * (Permissions::saveForUser() calls updateOrCreate()), not just granted or
 * revoked wholesale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('allowed')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
