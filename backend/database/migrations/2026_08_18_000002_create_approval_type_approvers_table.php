<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may approve a given approval_types row — see ApprovalType::isAuthorized().
 * Rows are only ever inserted/deleted (sync()), never edited, so no
 * updated_at — same convention as role_permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_type_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['approval_type_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_type_approvers');
    }
};
