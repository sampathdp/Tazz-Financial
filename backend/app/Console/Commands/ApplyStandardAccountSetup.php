<?php

namespace App\Console\Commands;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChargeCategory;
use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off, reviewable clean-up of the configuration the audit found unset or off-scheme:
 *   C-13  accounts numbered outside their class range (6060/6061 expense, 6062 income) are renumbered
 *   C-6   expense categories pointing at a header (5200) or nothing are mapped to the specific account
 *   C-5   charge categories with no income account are mapped explicitly
 *   B-6   tills with no purpose get one from their name
 *   new   branches with no main till get one (new branches get it automatically on creation)
 *   B-10  bank accounts are only LISTED for a person to confirm - a branch cannot be guessed
 *
 * Dry run by default: prints the plan and changes nothing. Pass --apply to make the changes (one transaction).
 * Safe to re-run: it only touches rows that still need it.
 */
class ApplyStandardAccountSetup extends Command
{
    protected $signature = 'accounts:apply-standard-setup {--apply : make the changes (default is a dry run)}';

    protected $description = 'Apply the standard chart-of-accounts, category and till configuration found missing by the audit (dry run unless --apply)';

    /** old code => [new code, new name] - same account id, so every posting follows the renumbering. */
    private const RENUMBER = [
        '6060' => ['5230', 'Incentive - Fixed Deposits'],
        '6061' => ['5240', 'Incentive - Loans'],
        '6062' => ['4950', 'Service Charge Income'],
    ];

    /** expense category name (lower case) => account code. Anything else falls back to 5010 Operating Expenses. */
    private const EXPENSE_MAP = [
        'salary' => '5210',
        'staff allowance' => '5220',
        'incentive fd' => '5230',
        'loan incentive' => '5240',
    ];

    /** charge category name (lower case) => account code. Anything else uses 4020 Loan Charges / Fees. */
    private const CHARGE_MAP = [
        'document' => '4910',
        'service charges' => '4950',
    ];

    /** till name pattern => purpose. */
    private const PURPOSE_PATTERNS = [
        '/\bfd\b|fixed deposit/i' => CashAccount::PURPOSE_FD,
        '/doc/i' => CashAccount::PURPOSE_DOCUMENT_CHARGES,
        '/collect/i' => CashAccount::PURPOSE_COLLECTIONS,
        '/saving/i' => CashAccount::PURPOSE_SAVINGS,
        '/pawn/i' => CashAccount::PURPOSE_PAWNING,
        '/disburs/i' => CashAccount::PURPOSE_DISBURSEMENTS,
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $plan = [];
        $changes = [];

        // C-13 renumber
        foreach (self::RENUMBER as $old => [$new, $name]) {
            $acc = ChartOfAccount::where('code', $old)->first();
            if (!$acc || ChartOfAccount::where('code', $new)->exists()) {
                continue;
            }
            $plan[] = ['C-13', "Account {$old} {$acc->name}", "renumber to {$new} ({$name})"];
            $changes[] = fn () => $acc->update(['code' => $new, 'name' => $name]);
        }

        // after renumbering, codes resolve by either the old or the new value
        $byCode = fn (string $code) => ChartOfAccount::whereIn('code', array_merge([$code], array_keys(array_filter(self::RENUMBER, fn ($r) => $r[0] === $code))))->first();

        // C-6 expense categories
        $headerIds = ChartOfAccount::where('code', '5200')->pluck('id')->all();
        foreach (ExpenseCategory::all() as $cat) {
            if ($cat->chart_of_account_id && !in_array((int) $cat->chart_of_account_id, array_map('intval', $headerIds), true)) {
                continue;
            }
            $code = self::EXPENSE_MAP[mb_strtolower(trim($cat->name))] ?? '5010';
            $target = $byCode($code);
            if (!$target || (int) $target->id === (int) $cat->chart_of_account_id) {
                continue;
            }
            $plan[] = ['C-6', "Expense category \"{$cat->name}\"", "post to {$code} {$target->name}"];
            $changes[] = fn () => $cat->update(['chart_of_account_id' => $target->id]);
        }

        // C-5 charge categories
        foreach (ChargeCategory::whereNull('chart_of_account_id')->get() as $cat) {
            $code = self::CHARGE_MAP[mb_strtolower(trim($cat->name))] ?? '4020';
            $target = $byCode($code);
            if (!$target) {
                continue;
            }
            $plan[] = ['C-5', "Charge category \"{$cat->name}\"", "post to {$code} {$target->name}"];
            $changes[] = fn () => $cat->update(['chart_of_account_id' => $target->id]);
        }

        // B-6 till purposes (primary tills with none set)
        foreach (CashAccount::withoutGlobalScopes()->whereIn('account_type', CashAccount::PRIMARY_TYPES)->where(fn ($q) => $q->whereNull('purpose')->orWhere('purpose', ''))->get() as $till) {
            $purpose = CashAccount::PURPOSE_GENERAL;
            foreach (self::PURPOSE_PATTERNS as $pattern => $p) {
                if (preg_match($pattern, $till->name)) {
                    $purpose = $p;
                    break;
                }
            }
            $plan[] = ['B-6', "Till \"{$till->name}\" (branch {$till->branch_id})", "purpose {$purpose}"];
            $changes[] = fn () => $till->update(['purpose' => $purpose]);
        }

        // Branches created before default accounts existed: give each the main till it is missing.
        foreach (\App\Models\Branch::where('status', 'Active')->get() as $branch) {
            $has = CashAccount::withoutGlobalScopes()->where('branch_id', $branch->id)->whereIn('account_type', CashAccount::PRIMARY_TYPES)->where('status', 'Active')->exists();
            if (!$has) {
                $plan[] = ['B-NEW', "Branch \"{$branch->name}\"", 'create its main till and ledger account'];
                $changes[] = fn () => \App\Support\BranchProvisioner::provision($branch);
            }
        }

        // B-10 bank accounts: list for confirmation, never change
        foreach (BankAccount::withoutGlobalScopes()->get() as $bank) {
            $plan[] = ['B-10', "Bank account \"{$bank->name}\"", 'tagged branch ' . ($bank->branch_id ?? 'none (shared)') . ' - CONFIRM (not changed)'];
        }

        if (!$plan) {
            $this->info('Nothing to do: the configuration already follows the standard setup.');

            return 0;
        }
        $this->table(['Item', 'Target', 'Action'], $plan);

        if (!$apply) {
            $this->warn('Dry run: nothing was changed. Re-run with --apply to make these changes.');

            return 0;
        }
        DB::transaction(function () use ($changes) {
            foreach ($changes as $change) {
                $change();
            }
        });
        $this->info(count($changes) . ' change(s) applied.');

        return 0;
    }
}
