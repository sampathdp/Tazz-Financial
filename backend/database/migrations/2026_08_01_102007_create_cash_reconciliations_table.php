<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateCashReconciliationsTable extends Migration
{
    public function up()
    {
        Schema::create('cash_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('as_at_date');

            /** Snapshots of live system figures at the moment this was saved — not retroactively recalculated. */
            $table->decimal('system_cash_total', 15, 2)->unsigned()->default(0); // sum of the branch's cash accounts
            $table->decimal('cheque_in_hand_total', 15, 2)->unsigned()->default(0); // undeposited Cheque-method loan payments, clearance Pending
            $table->decimal('postdated_cheque_total', 15, 2)->unsigned()->default(0); // post-dated cheques not yet realized

            // Informational-only figures for the as_at_date, pulled from Loan Payments — not
            // folded into total_cash/total_cheque below since cash accounts don't yet post
            // from loan payments (see CashbookEntry's source_type/source_id).
            $table->decimal('today_cash_receipts', 15, 2)->unsigned()->default(0); // Cash-method loan payments received that day
            $table->decimal('today_cheque_receipts', 15, 2)->unsigned()->default(0); // Cheque-method loan payments received that day
            $table->decimal('cheque_realized_total', 15, 2)->unsigned()->default(0); // Cheque-method loan payments realized that day

            // Manual adjustment fields (cashier-entered)
            $table->decimal('receipts_to_be_cancelled', 15, 2)->unsigned()->default(0);
            $table->decimal('encashment', 15, 2)->unsigned()->default(0);
            $table->decimal('cash_float', 15, 2)->unsigned()->default(0);
            $table->decimal('petty_cash', 15, 2)->unsigned()->default(0);
            $table->decimal('other_branch_cash', 15, 2)->unsigned()->default(0);
            // Was a free-text "branch code" typed into the form with no
            // lookup/validation against the real branches table at all —
            // replaced with a real FK. The controller's validation moves
            // from string|max:16 to exists:branches,id.
            $table->foreignId('other_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->decimal('other_branch_cheque', 15, 2)->unsigned()->default(0);
            $table->foreignId('other_branch_cheque_branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Computed totals, stored for history/audit
            $table->decimal('total_cash', 15, 2)->unsigned()->default(0);
            $table->decimal('total_cheque', 15, 2)->unsigned()->default(0);

            // Physical note count. MariaDB has no native JSON type (it's a
            // LONGTEXT alias) — Laravel's json() column type doesn't add the
            // validity check on this driver, so it's done explicitly here to
            // actually get MySQL/MariaDB-enforced JSON validity either way.
            $table->longText('denominations')->nullable()->charset('utf8mb4')->collation('utf8mb4_bin');
            $table->decimal('coins_amount', 15, 2)->unsigned()->default(0);
            $table->decimal('counted_total', 15, 2)->unsigned()->default(0);
            $table->decimal('variance', 15, 2)->default(0); // signed — a shortage is negative

            $table->string('notes', 255)->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'as_at_date']);
        });

        DB::statement('ALTER TABLE `cash_reconciliations` ADD CONSTRAINT `chk_cr_denominations_json` CHECK (`denominations` IS NULL OR JSON_VALID(`denominations`))');
    }

    public function down()
    {
        Schema::dropIfExists('cash_reconciliations');
    }
}
