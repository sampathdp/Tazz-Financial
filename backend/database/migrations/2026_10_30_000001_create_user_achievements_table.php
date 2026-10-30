<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_achievements')) {
            return;
        }
        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->date('achieved_on')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'achieved_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_achievements');
    }
};
