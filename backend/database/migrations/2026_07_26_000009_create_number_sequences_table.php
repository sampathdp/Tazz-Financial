<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('module', 32); // loan | customer | payment | ...
            // 0 = "not part of this sequence's key" (kept non-null so the unique
            // index below actually enforces one row per key — MySQL treats NULLs
            // in a unique index as distinct from each other, which would let
            // duplicate "global" rows slip in).
            $table->unsignedBigInteger('branch_id')->default(0);
            $table->unsignedBigInteger('loan_type_id')->default(0);
            $table->unsignedSmallInteger('year')->default(0);
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['module', 'branch_id', 'loan_type_id', 'year'], 'number_sequences_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
