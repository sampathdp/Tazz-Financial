<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            // Editable, back-datable application date shown/printed as the loan's
            // date — separate from `created_at`, which Eloquent manages and always
            // reflects the real save time regardless of this value.
            $table->date('application_date')->nullable()->after('purpose');
        });

        // Existing applications didn't have this field — default it to the day
        // they were actually created so historical data still shows a date.
        DB::table('loans')->whereNull('application_date')->update(['application_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('application_date');
        });
    }
};
