<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_types', function (Blueprint $table) {
            // Template for generating each loan's reference number, e.g.
            // "{BRANCH}-{CODE}-{YEAR}-{SEQ:6}". Null falls back to that default
            // at generation time (see App\Services\NumberGenerator).
            $table->string('reference_format', 191)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('loan_types', function (Blueprint $table) {
            $table->dropColumn('reference_format');
        });
    }
};
