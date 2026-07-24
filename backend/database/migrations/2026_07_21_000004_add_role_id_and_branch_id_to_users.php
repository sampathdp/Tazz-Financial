<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AddRoleIdAndBranchIdToUsers extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('password')->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('role_id')->constrained()->nullOnDelete();
        });

        // Backfill: one Role row per distinct legacy role string, then point users at it.
        $legacyRoles = DB::table('users')->whereNotNull('role')->distinct()->pluck('role');
        foreach ($legacyRoles as $slug) {
            $isAdmin = $slug === 'admin';
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (!$roleId) {
                $roleId = DB::table('roles')->insertGetId([
                    'name' => Str::title(str_replace('_', ' ', $slug)),
                    'slug' => $slug,
                    'is_system' => $isAdmin,
                    'can_view_all_branches' => $isAdmin,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('users')->where('role', $slug)->update(['role_id' => $roleId]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'branch']);
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('user')->after('password');
            $table->index('role');
            $table->string('branch', 64)->nullable()->after('role');
        });

        $users = DB::table('users')->whereNotNull('role_id')->get(['id', 'role_id']);
        foreach ($users as $u) {
            $slug = DB::table('roles')->where('id', $u->role_id)->value('slug') ?: 'user';
            DB::table('users')->where('id', $u->id)->update(['role' => $slug]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['role_id', 'branch_id']);
        });
    }
}
