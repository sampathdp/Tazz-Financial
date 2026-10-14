<?php

use App\Models\CashAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every cash account has a purpose, and a branch has at most one primary till per dedicated purpose.
 *
 *  - Empty purposes are filled: a primary till takes the purpose its name suggests ("Doc charge" =>
 *    DocumentCharges), everything else is General.
 *  - If a branch already has two primary tills for one dedicated purpose, the newer one becomes General.
 *  - purpose becomes NOT NULL DEFAULT 'General'.
 *  - purpose_key (generated) is the purpose for primary tills with a dedicated purpose and NULL otherwise;
 *    a unique index on (branch_id, purpose_key) stops a second till for the same purpose.
 *  - Branches are then given any dedicated till they are missing (see BranchProvisioner).
 */
return new class extends Migration
{
    public function up(): void
    {
        $primary = CashAccount::PRIMARY_TYPES;

        foreach (DB::table('cash_accounts')->where(fn ($q) => $q->whereNull('purpose')->orWhere('purpose', ''))->get(['id', 'name', 'account_type']) as $row) {
            $purpose = in_array($row->account_type, $primary, true) ? CashAccount::purposeFromName((string) $row->name) : CashAccount::PURPOSE_GENERAL;
            DB::table('cash_accounts')->where('id', $row->id)->update(['purpose' => $purpose]);
        }
        // Dedicated purposes belong to primary tills only.
        DB::table('cash_accounts')->whereNotIn('account_type', $primary)->where('purpose', '!=', CashAccount::PURPOSE_GENERAL)->update(['purpose' => CashAccount::PURPOSE_GENERAL]);

        $seen = [];
        foreach (DB::table('cash_accounts')->whereIn('account_type', $primary)->whereIn('purpose', CashAccount::DEDICATED_PURPOSES)->orderBy('id')->get(['id', 'branch_id', 'purpose']) as $row) {
            $key = $row->branch_id . '|' . $row->purpose;
            if (isset($seen[$key])) {
                DB::table('cash_accounts')->where('id', $row->id)->update(['purpose' => CashAccount::PURPOSE_GENERAL]);
            }
            $seen[$key] = true;
        }

        DB::statement("ALTER TABLE cash_accounts MODIFY purpose VARCHAR(24) NOT NULL DEFAULT 'General'");

        if (!Schema::hasColumn('cash_accounts', 'purpose_key')) {
            $types = "'" . implode("','", $primary) . "'";
            DB::statement("ALTER TABLE cash_accounts ADD COLUMN purpose_key VARCHAR(24) GENERATED ALWAYS AS (CASE WHEN purpose <> 'General' AND account_type IN ({$types}) THEN purpose ELSE NULL END) STORED");
            DB::statement('ALTER TABLE cash_accounts ADD UNIQUE INDEX uq_cash_accounts_branch_purpose (branch_id, purpose_key)');
        }

        foreach (\App\Models\Branch::where('status', 'Active')->get() as $branch) {
            \App\Support\BranchProvisioner::provision($branch);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cash_accounts', 'purpose_key')) {
            DB::statement('ALTER TABLE cash_accounts DROP INDEX uq_cash_accounts_branch_purpose');
            DB::statement('ALTER TABLE cash_accounts DROP COLUMN purpose_key');
        }
        DB::statement('ALTER TABLE cash_accounts MODIFY purpose VARCHAR(24) NULL');
    }
};
