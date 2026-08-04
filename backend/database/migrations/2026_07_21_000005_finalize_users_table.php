<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the base Laravel users table now that roles/branches exist to
 * point at. Consolidates what used to be four separate historical steps
 * (add a `role` string column, add a `branch` string + `status` column, add
 * real role_id/branch_id foreign keys with a string-to-id backfill, then
 * drop the now-dead `role`/`branch` strings) into the final shape directly —
 * there's no data to backfill on a fresh install, and this migration only
 * ever runs against an empty users table, so public_uuid can be declared
 * NOT NULL immediately rather than added nullable-then-backfilled-then-
 * tightened (which would need doctrine/dbal for the column ->change(), not
 * installed in this project).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('public_uuid', 36)->after('id');
            $table->foreignId('role_id')->nullable()->after('password')->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('role_id')->constrained()->nullOnDelete();
            $table->enum('status', ['Active', 'Inactive'])->default('Active')->after('branch_id');
            $table->unique('public_uuid');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropUnique(['public_uuid']);
            $table->dropColumn('public_uuid');
        });
    }
};
