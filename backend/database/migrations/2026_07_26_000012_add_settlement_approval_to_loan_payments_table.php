<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSettlementApprovalToLoanPaymentsTable extends Migration
{
    public function up()
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            // A full settlement pays off the entire outstanding balance in one go
            // (as opposed to a regular/early installment payment) and must be
            // reviewed before it's allowed to close the loan — see
            // LoanPaymentController::store()/approveSettlement().
            $table->boolean('is_settlement')->default(false)->after('payment_method');
            $table->string('approval_status', 20)->default('Approved')->after('status'); // Approved | PendingApproval | Rejected
            $table->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down()
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['is_settlement', 'approval_status', 'approved_at']);
        });
    }
}
