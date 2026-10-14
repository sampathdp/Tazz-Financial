<?php
// Seeds a small, known set of journal entries into the ISOLATED TEST database for the accounting-report UI checks.
// Refuses to run against any database whose name does not end in "test". Idempotent: re-running replaces its own rows.
use App\Models\ChartOfAccount;
use App\Support\LedgerPoster;
use Illuminate\Support\Facades\DB;

$db = DB::connection()->getDatabaseName();
if (!str_ends_with($db, 'test')) { fwrite(STDERR, "refusing to seed non-test database {$db}\n"); exit(1); }

DB::transaction(function () {
    $old = DB::table('journal_entries')->where('description', 'like', 'E2E-RPT%')->pluck('id');
    DB::table('journal_lines')->whereIn('journal_entry_id', $old)->delete();
    DB::table('journal_entries')->whereIn('id', $old)->delete();

    $id = fn (string $code) => ChartOfAccount::where('code', $code)->value('id');
    $till = $id('1010-15'); $bank = $id('1020-2');
    $post = function (array $lines, string $date, string $label, int $branch = 1) {
        LedgerPoster::post($lines, $date, ['source_type' => 'ManualAdjustment', 'description' => "E2E-RPT {$label}", 'branch_id' => $branch]);
    };
    $post([['account_id' => $till, 'debit' => '5000.00'], ['account_id' => $id('3015'), 'credit' => '5000.00']], '2026-09-01', 'opening');
    $post([['account_id' => $till, 'debit' => '1000.00'], ['account_id' => $id('4010'), 'credit' => '1000.00']], '2026-09-10', 'loan interest');
    $post([['account_id' => $bank, 'debit' => '400.00'], ['account_id' => $till, 'credit' => '400.00']], '2026-09-12', 'banking');
    $post([['account_id' => $id('5300'), 'debit' => '300.00'], ['account_id' => $id('1190'), 'credit' => '300.00']], '2026-09-15', 'provision');
    $post([['account_id' => $till, 'debit' => '450.00'], ['account_id' => $id('4025'), 'credit' => '400.00'], ['account_id' => $id('4905'), 'credit' => '50.00']], '2026-09-16', 'pawning + overage', 4); // Horana (branch 4)
    // Head Office's expense paid out of Horana's till (cash account 16 belongs to branch 4): the ledger splits it
    // into a Head Office entry and a Horana entry joined by the inter-branch accounts. Dated after the September checks.
    $post([['account_id' => $id('5010'), 'debit' => '200.00'], ['account_id' => ChartOfAccount::where('code', '1010-16')->value('id'), 'credit' => '200.00']], '2026-10-05', 'inter-branch expense', 1);
});
echo "seeded\n";
