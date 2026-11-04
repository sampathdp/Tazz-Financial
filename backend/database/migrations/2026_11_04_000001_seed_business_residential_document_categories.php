<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class SeedBusinessResidentialDocumentCategories extends Migration
{
    private array $names = ['Business Photos', 'Residential'];

    public function up()
    {
        $order = (int) DB::table('document_categories')->max('sort_order');
        foreach ($this->names as $name) {
            if (DB::table('document_categories')->where('name', $name)->exists()) continue;
            DB::table('document_categories')->insert([
                'name' => $name,
                'status' => 'Active',
                'sort_order' => ++$order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        DB::table('document_categories')->whereIn('name', $this->names)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('facility_documents')
                ->whereColumn('facility_documents.document_category_id', 'document_categories.id'))
            ->delete();
    }
}
