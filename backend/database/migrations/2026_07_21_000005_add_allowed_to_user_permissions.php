<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_permissions rows now mean "explicit override of the role default" —
 * allowed=true forces a grant, allowed=false forces a denial, absence of a
 * row means "inherit the role's setting". Existing rows predate role-based
 * permissions and represented plain grants, so they default to true.
 */
class AddAllowedToUserPermissions extends Migration
{
    public function up()
    {
        Schema::table('user_permissions', function (Blueprint $table) {
            $table->boolean('allowed')->default(true)->after('action');
        });
    }

    public function down()
    {
        Schema::table('user_permissions', function (Blueprint $table) {
            $table->dropColumn('allowed');
        });
    }
}
