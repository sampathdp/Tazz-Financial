<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Lets journal entries be sourced as 'BranchFunding' (branch-to-branch funding agreements). */
return new class extends Migration
{
    public function up(): void
    {
        $type = DB::selectOne("SHOW COLUMNS FROM `journal_entries` WHERE Field = 'source_type'")->Type;
        if (str_contains($type, "'BranchFunding'")) {
            return;
        }
        DB::statement('ALTER TABLE `journal_entries` MODIFY `source_type` ' . substr($type, 0, -1) . ",'BranchFunding') DEFAULT NULL");
    }

    public function down(): void
    {
        // Left in place: removing an enum value would fail once rows use it.
    }
};
