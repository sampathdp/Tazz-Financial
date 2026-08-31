<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank account a loan / speed draft was paid out of when it is disbursed
 * by Bank transfer or Cheque. Cash disbursements leave it null (the branch
 * till is derived from branch_id instead). Without this there was no
 * unambiguous account to credit, so Bank/Cheque disbursements were never
 * posted to the general ledger at all — while every principal collection
 * against the same facility WAS posted, driving 1100 / 1110 into a large
 * abnormal credit balance (see LoanLedgerPoster / SpeedDraftLedgerPoster).
 *
 * FK names are set by hand: the auto-generated
 * "<table>_disbursement_bank_account_id_foreign" exceeds MySQL's 64-char
 * identifier limit for speed_draft_facility_details.
 */
return new class extends Migration
{
    private const TABLES = [
        'loan_facility_details' => 'lfd_disb_bank_acc_fk',
        'speed_draft_facility_details' => 'sdfd_disb_bank_acc_fk',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $fk) {
            if (Schema::hasColumn($table, 'disbursement_bank_account_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($fk) {
                $t->unsignedBigInteger('disbursement_bank_account_id')->nullable()->after('disbursement_method');
                $t->foreign('disbursement_bank_account_id', $fk)->references('id')->on('bank_accounts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (!Schema::hasColumn($table, 'disbursement_bank_account_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                // drop whichever FK name landed (see up() — the first run may
                // have created the auto-named one before this file was hardened)
                try {
                    $t->dropForeign(['disbursement_bank_account_id']);
                } catch (\Throwable $e) {
                    // no FK, or a non-standard name — column drop still proceeds
                }
                $t->dropColumn('disbursement_bank_account_id');
            });
        }
    }
};
