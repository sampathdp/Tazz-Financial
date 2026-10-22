<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Daily cash sessions are no longer used (screen and setting toggle removed), so make sure
 * the gate can never be left switched on from before.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('finance_settings')->where('key', 'require_cash_session')->update(['value' => '0']);
    }

    public function down(): void
    {
        // Intentionally empty: the previous value is not worth restoring.
    }
};
