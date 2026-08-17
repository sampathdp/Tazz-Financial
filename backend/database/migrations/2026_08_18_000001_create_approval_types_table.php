<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A data-driven registry of distinct "approval points" in the system — each
 * row is a named thing that requires its own hand-picked pool of approvers
 * (approval_type_approvers) and its own required-approvals count, separate
 * from the module/action permission grid (Permissions::MODULES). Being able
 * to approve one type (e.g. loan_cancel) never implies being able to approve
 * another (e.g. payment_void) — see ApprovalType::isAuthorized().
 *
 * New approval points ship as new `code`s wired into a controller (a code
 * change, always via approval_requests — see that table), but everything
 * about WHO may approve an existing code and HOW MANY approvals it needs is
 * pure data from then on — managed via the Approval Types admin screen, no
 * redeploy needed. is_system flags the two codes this feature ships with
 * (loan_cancel, payment_void) so they can't be deleted out from under the
 * controllers that check for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 128);
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedTinyInteger('required_approvals')->default(1);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE `approval_types` ADD CONSTRAINT `chk_approval_types_required_approvals` CHECK (`required_approvals` IN (1, 2))');

        DB::table('approval_types')->insert([
            ['code' => 'loan_cancel', 'name' => 'Loan Cancellation', 'description' => 'Approving a request to cancel an approved-not-yet-disbursed or freshly-disbursed loan.', 'is_system' => true, 'required_approvals' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'payment_void', 'name' => 'Payment Receipt Cancellation', 'description' => 'Approving a request to void a loan payment receipt.', 'is_system' => true, 'required_approvals' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_types');
    }
};
