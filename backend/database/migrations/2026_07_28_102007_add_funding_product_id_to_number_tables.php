<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // number_sequences.loan_type_id is a hard FK to loan_types, so a funding
        // product's own per-product sequence needs its own key column rather than
        // overloading that one (see NumberGenerator's fixed_deposit handling).
        Schema::table('number_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('funding_product_id')->default(0)->after('loan_type_id');
        });
        Schema::table('number_sequences', function (Blueprint $table) {
            $table->dropUnique('number_sequences_key_unique');
            $table->unique(['module', 'branch_id', 'loan_type_id', 'funding_product_id', 'year'], 'number_sequences_key_unique');
        });

        Schema::table('generated_numbers', function (Blueprint $table) {
            $table->foreignId('funding_product_id')->nullable()->after('loan_type_id')->constrained('funding_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generated_numbers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funding_product_id');
        });
        Schema::table('number_sequences', function (Blueprint $table) {
            $table->dropUnique('number_sequences_key_unique');
            $table->unique(['module', 'branch_id', 'loan_type_id', 'year'], 'number_sequences_key_unique');
            $table->dropColumn('funding_product_id');
        });
    }
};
