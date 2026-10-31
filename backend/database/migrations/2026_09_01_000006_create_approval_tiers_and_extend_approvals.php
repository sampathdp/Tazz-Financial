<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance module — Phase 2. Amount-banded, per-branch approval routing on top
 * of the existing shared approval engine (approval_types /
 * approval_type_approvers / approval_requests).
 *
 *  - approval_tiers: for an approval_types code (and optionally a branch), a
 *    band [min_amount, max_amount] maps to how many distinct approvals are
 *    required. At submit time ApprovalRouter picks the matching row and
 *    snapshots required_approvals onto the approval_requests row, exactly the
 *    way required_approvals is already snapshotted from approval_types today.
 *    No tier row for a code => fall back to approval_types.required_approvals
 *    (so every existing flow is unchanged).
 *
 *  - approval_requests gains third_/fourth_approved_by slots so a tier can
 *    require up to four signers. The existing controllers only ever fill the
 *    first two and are untouched; ApprovalRequest::recordApproval() fills the
 *    next free slot for the new Finance flows.
 *
 *  - the approval_types.required_approvals CHECK is widened 1..2 => 1..4.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `approval_types` DROP CONSTRAINT `chk_approval_types_required_approvals`');
        DB::statement('ALTER TABLE `approval_types` ADD CONSTRAINT `chk_approval_types_required_approvals` CHECK (`required_approvals` between 1 and 4)');

        Schema::table('approval_requests', function (Blueprint $t) {
            $t->unsignedBigInteger('third_approved_by')->nullable()->after('second_approved_at');
            $t->dateTime('third_approved_at')->nullable()->after('third_approved_by');
            $t->unsignedBigInteger('fourth_approved_by')->nullable()->after('third_approved_at');
            $t->dateTime('fourth_approved_at')->nullable()->after('fourth_approved_by');
            $t->foreign('third_approved_by', 'fk_ar_third_approved_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('fourth_approved_by', 'fk_ar_fourth_approved_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('approval_tiers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('approval_type_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->decimal('min_amount', 15, 2)->default(0);
            $t->decimal('max_amount', 15, 2)->nullable();
            $t->unsignedTinyInteger('required_approvals')->default(1);
            $t->string('notes', 255)->nullable();
            $t->timestamps();

            $t->foreign('approval_type_id', 'fk_atier_type')->references('id')->on('approval_types')->cascadeOnDelete();
            $t->foreign('branch_id', 'fk_atier_branch')->references('id')->on('branches')->nullOnDelete();
            $t->index(['approval_type_id', 'branch_id'], 'ix_atier_type_branch');
        });

        DB::statement('ALTER TABLE `approval_tiers` ADD CONSTRAINT `chk_atier_required_approvals` CHECK (`required_approvals` between 1 and 4)');
        DB::statement('ALTER TABLE `approval_tiers` ADD CONSTRAINT `chk_atier_band` CHECK (`max_amount` is null or `max_amount` >= `min_amount`)');
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_tiers');

        Schema::table('approval_requests', function (Blueprint $t) {
            $t->dropForeign('fk_ar_third_approved_by');
            $t->dropForeign('fk_ar_fourth_approved_by');
            $t->dropColumn(['third_approved_by', 'third_approved_at', 'fourth_approved_by', 'fourth_approved_at']);
        });

        DB::statement('ALTER TABLE `approval_types` DROP CONSTRAINT `chk_approval_types_required_approvals`');
        DB::statement('ALTER TABLE `approval_types` ADD CONSTRAINT `chk_approval_types_required_approvals` CHECK (`required_approvals` in (1,2))');
    }
};
