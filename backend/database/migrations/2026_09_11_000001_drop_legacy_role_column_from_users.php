<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dead migration file from before the role_id/Role-relation redesign
 * (originally 2026_06_26_000001_add_role_to_users, removed from the repo at
 * commit 574315e once finalize_users_table took over) was left physically on
 * the production server by a sync-style deploy that only uploads added/
 * changed files and never deletes ones removed from git. A later deploy's
 * "run new migration files" step then executed that orphan file for the
 * first time, adding a `role` string column back onto `users`.
 *
 * That column shadows the `role()` belongsTo(Role::class) relation: Eloquent
 * resolves a real attribute before it calls a same-named relationship
 * method, so every `$user->role` in the app started returning the raw
 * string instead of the Role model, and `$user->role?->slug` (AuthController,
 * UserController, PermissionController, CurrentBranch, Permissions,
 * BelongsToBranch, ...) blew up with "Attempt to read property on string" —
 * crashing login right after a correct password check.
 *
 * This only drops the column if present, so it's a no-op on any environment
 * that never had the stray file replayed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }

    public function down(): void
    {
        // Intentionally not restored — the string `role` column was never a
        // real part of the schema after finalize_users_table; role_id is the
        // source of truth.
    }
};
