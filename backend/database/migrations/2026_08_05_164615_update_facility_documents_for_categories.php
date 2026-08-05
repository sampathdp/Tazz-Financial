<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateFacilityDocumentsForCategories extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 1. Seed initial categories based on existing enum
        $categories = [
            'ContractImage' => 'Contract Image',
            'Agreement' => 'Agreement',
            'IdCopy' => 'ID Copy',
            'Other' => 'Other'
        ];
        
        $categoryIds = [];
        $order = 1;
        foreach ($categories as $key => $name) {
            $existing = DB::table('document_categories')->where('name', $name)->first();
            if ($existing) {
                $categoryIds[$key] = $existing->id;
            } else {
                $categoryIds[$key] = DB::table('document_categories')->insertGetId([
                    'name' => $name,
                    'status' => 'Active',
                    'sort_order' => $order++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 2. Add foreign key to facility_documents (if not exists from a previous failed run)
        if (!Schema::hasColumn('facility_documents', 'document_category_id')) {
            Schema::table('facility_documents', function (Blueprint $table) {
                $table->foreignId('document_category_id')->nullable()->after('credit_facility_id')->constrained('document_categories')->restrictOnDelete();
            });
        }

        // 3. Migrate data
        foreach ($categoryIds as $key => $id) {
            DB::table('facility_documents')->where('doc_type', $key)->update(['document_category_id' => $id]);
        }

        // 4. Drop old enum
        if (Schema::hasColumn('facility_documents', 'doc_type')) {
            Schema::table('facility_documents', function (Blueprint $table) {
                $table->dropColumn('doc_type');
            });
        }
    }

    public function down()
    {
        Schema::table('facility_documents', function (Blueprint $table) {
            $table->enum('doc_type', ['ContractImage', 'Agreement', 'IdCopy', 'Other'])->after('credit_facility_id')->default('ContractImage');
        });

        DB::table('facility_documents')->update([
            'doc_type' => DB::raw("
                CASE document_category_id 
                    WHEN (SELECT id FROM document_categories WHERE name = 'Contract Image') THEN 'ContractImage'
                    WHEN (SELECT id FROM document_categories WHERE name = 'Agreement') THEN 'Agreement'
                    WHEN (SELECT id FROM document_categories WHERE name = 'ID Copy') THEN 'IdCopy'
                    ELSE 'Other'
                END
            ")
        ]);

        Schema::table('facility_documents', function (Blueprint $table) {
            $table->dropForeign(['document_category_id']);
            $table->dropColumn('document_category_id');
        });
    }
}
