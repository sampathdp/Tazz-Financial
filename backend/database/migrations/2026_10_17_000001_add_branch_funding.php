<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-to-branch cash funding, routed through Head Office (hub and spoke).
 *
 * A lender branch B lends cash to a borrower branch A. The two branches never owe each other directly: B is
 * owed by Head Office and A owes Head Office, and Head Office carries both sides. Company-wide every one of the
 * accounts below nets to zero, so statements hide them (see AccountingReportController::eliminatedAccountIds).
 *
 *   1410 Branch Funding Receivable (Lent via HO)      lender branch's claim on Head Office
 *   2410 Branch Funding Payable (Borrowed via HO)     borrower branch's debt to Head Office
 *   1411 Due from Borrower Branches (Funding)         Head Office's claim on the borrower
 *   2411 Due to Lender Branches (Funding)             Head Office's debt to the lender
 *   5130 Inter-Branch Interest Expense                borrower's cost; mirror of 4040 Inter Branch Interest Income
 *
 * It also renames the 1400 / 2400 clearing accounts to the Head Office wording the client uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['1410', 'Branch Funding Receivable (Lent via Head Office)', 'Asset', 'Debit'],
            ['1411', 'Due from Borrower Branches (Funding)', 'Asset', 'Debit'],
            ['2410', 'Branch Funding Payable (Borrowed via Head Office)', 'Liability', 'Credit'],
            ['2411', 'Due to Lender Branches (Funding)', 'Liability', 'Credit'],
            ['5130', 'Inter-Branch Interest Expense', 'Expense', 'Debit'],
        ] as [$code, $name, $type, $normal]) {
            if (!DB::table('chart_of_accounts')->where('code', $code)->exists()) {
                DB::table('chart_of_accounts')->insert([
                    'code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $normal,
                    'parent_id' => null, 'is_system' => true, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        DB::table('chart_of_accounts')->where('code', '1400')->update(['name' => 'Head Office Account (Due from Head Office / Branches)']);
        DB::table('chart_of_accounts')->where('code', '2400')->update(['name' => 'Branch Current Account (Due to Head Office / Branches)']);

        Schema::table('branches', function (Blueprint $t) {
            if (!Schema::hasColumn('branches', 'funding_borrow_limit')) {
                // Most this branch may owe other branches through funding agreements; null = no limit.
                $t->decimal('funding_borrow_limit', 15, 2)->nullable();
            }
        });

        Schema::create('branch_funding_agreements', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->unsignedBigInteger('lender_branch_id')->index();
            $t->unsignedBigInteger('borrower_branch_id')->index();
            $t->unsignedBigInteger('lender_account_id');   // chart_of_accounts id of the lender's cash / bank account
            $t->unsignedBigInteger('borrower_account_id'); // chart_of_accounts id of the borrower's cash / bank account
            $t->decimal('principal_amount', 15, 2);
            $t->decimal('outstanding_principal', 15, 2)->default(0);
            $t->decimal('interest_rate_percent', 8, 3)->default(0); // per year, simple interest on the outstanding principal
            $t->string('accrual_mode', 20)->default('monthly');      // monthly | on_repayment
            $t->date('interest_accrued_through')->nullable();
            $t->decimal('accrued_interest_unpaid', 15, 2)->default(0);
            $t->date('request_date');
            $t->date('disbursed_on')->nullable();
            $t->string('purpose')->nullable();
            $t->text('notes')->nullable();
            $t->string('status', 20)->default('Requested'); // Requested | Active | Closed | Rejected
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });

        Schema::create('branch_funding_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agreement_id')->index();
            $t->string('kind', 20); // Disbursement | Interest | Repayment
            $t->date('txn_date');
            $t->decimal('principal_amount', 15, 2)->default(0);
            $t->decimal('interest_amount', 15, 2)->default(0);
            $t->unsignedBigInteger('journal_entry_id')->nullable(); // the Head Office entry; branch entries link to it
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_funding_transactions');
        Schema::dropIfExists('branch_funding_agreements');
        Schema::table('branches', function (Blueprint $t) {
            if (Schema::hasColumn('branches', 'funding_borrow_limit')) {
                $t->dropColumn('funding_borrow_limit');
            }
        });
        DB::table('chart_of_accounts')->whereIn('code', ['1410', '1411', '2410', '2411', '5130'])->delete();
        DB::table('chart_of_accounts')->where('code', '1400')->update(['name' => 'Inter-Branch Receivable (Due from Branches)']);
        DB::table('chart_of_accounts')->where('code', '2400')->update(['name' => 'Inter-Branch Payable (Due to Branches)']);
    }
};
