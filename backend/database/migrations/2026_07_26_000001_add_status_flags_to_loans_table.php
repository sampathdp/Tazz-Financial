<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->boolean('is_terminated')->default(false)->after('status');
            $table->dateTime('terminated_at')->nullable()->after('is_terminated');
            $table->boolean('is_legal')->default(false)->after('terminated_at');
            $table->dateTime('legal_at')->nullable()->after('is_legal');
            $table->boolean('seizing_order_issued')->default(false)->after('legal_at');
            $table->dateTime('seizing_order_at')->nullable()->after('seizing_order_issued');
            $table->boolean('is_repossessed')->default(false)->after('seizing_order_at');
            $table->dateTime('repossessed_at')->nullable()->after('is_repossessed');
            $table->date('freeze_date')->nullable()->after('repossessed_at');
            $table->date('confirmed_date')->nullable()->after('freeze_date');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_date')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn([
                'is_terminated', 'terminated_at',
                'is_legal', 'legal_at',
                'seizing_order_issued', 'seizing_order_at',
                'is_repossessed', 'repossessed_at',
                'freeze_date', 'confirmed_date',
            ]);
        });
    }
};
