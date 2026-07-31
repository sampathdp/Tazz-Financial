<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('reference_name', 150)->nullable()->after('purpose');
            $table->string('reference_id_no', 64)->nullable()->after('reference_name'); // NIC or other ID (passport, driving license, etc.)
            $table->string('reference_address', 255)->nullable()->after('reference_id_no');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['reference_name', 'reference_id_no', 'reference_address']);
        });
    }
};
