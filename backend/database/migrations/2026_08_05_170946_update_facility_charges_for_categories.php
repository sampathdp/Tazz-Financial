<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateFacilityChargesForCategories extends Migration
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
            'Document' => 'Document',
            'Legal' => 'Legal',
            'Valuation' => 'Valuation',
            'Other' => 'Other'
        ];
        
        $categoryIds = [];
        $order = 1;
        foreach ($categories as $key => $name) {
            $existing = \Illuminate\Support\Facades\DB::table('charge_categories')->where('name', $name)->first();
            if ($existing) {
                $categoryIds[$key] = $existing->id;
            } else {
                $categoryIds[$key] = \Illuminate\Support\Facades\DB::table('charge_categories')->insertGetId([
                    'name' => $name,
                    'status' => 'Active',
                    'sort_order' => $order++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 2. Add foreign key to facility_charges
        if (!Schema::hasColumn('facility_charges', 'charge_category_id')) {
            Schema::table('facility_charges', function (Blueprint $table) {
                $table->foreignId('charge_category_id')->nullable()->after('branch_id')->constrained('charge_categories')->restrictOnDelete();
            });
        }

        // 3. Migrate data
        foreach ($categoryIds as $key => $id) {
            \Illuminate\Support\Facades\DB::table('facility_charges')->where('charge_type', $key)->update(['charge_category_id' => $id]);
        }

        // 4. Drop old enum and old index
        Schema::table('facility_charges', function (Blueprint $table) {
            $table->dropIndex(['credit_facility_id', 'charge_type']);
        });

        if (Schema::hasColumn('facility_charges', 'charge_type')) {
            Schema::table('facility_charges', function (Blueprint $table) {
                $table->dropColumn('charge_type');
            });
        }

        // Add new index
        Schema::table('facility_charges', function (Blueprint $table) {
            $table->index(['credit_facility_id', 'charge_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('facility_charges', function (Blueprint $table) {
            $table->dropIndex(['credit_facility_id', 'charge_category_id']);
            $table->enum('charge_type', ['Document', 'Legal', 'Valuation', 'Other'])->after('branch_id')->default('Document');
        });

        \Illuminate\Support\Facades\DB::table('facility_charges')->update([
            'charge_type' => \Illuminate\Support\Facades\DB::raw("
                CASE charge_category_id 
                    WHEN (SELECT id FROM charge_categories WHERE name = 'Document') THEN 'Document'
                    WHEN (SELECT id FROM charge_categories WHERE name = 'Legal') THEN 'Legal'
                    WHEN (SELECT id FROM charge_categories WHERE name = 'Valuation') THEN 'Valuation'
                    ELSE 'Other'
                END
            ")
        ]);

        Schema::table('facility_charges', function (Blueprint $table) {
            $table->dropForeign(['charge_category_id']);
            $table->dropColumn('charge_category_id');
            $table->index(['credit_facility_id', 'charge_type']);
        });
    }
}
