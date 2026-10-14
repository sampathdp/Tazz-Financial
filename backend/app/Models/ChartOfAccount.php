<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ChartOfAccount extends Model
{
    protected $table = 'chart_of_accounts';

    protected $fillable = ['code', 'name', 'account_type', 'normal_balance', 'parent_id', 'is_system', 'is_postable', 'status'];

    protected $casts = [
        'is_system' => 'boolean',
        'is_postable' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_id');
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    public function cashAccount()
    {
        return $this->hasOne(CashAccount::class);
    }

    public function bankAccount()
    {
        return $this->hasOne(BankAccount::class);
    }

    /** A seeded control account by its code (e.g. '1100', '4010') — throws with a clear message if the chart-of-accounts seed hasn't run, instead of a bare "trying to get property of null" further down. */
    public static function system(string $code): self
    {
        return static::where('code', $code)->firstOr(function () use ($code) {
            throw new \RuntimeException("Chart of accounts is missing expected control account '{$code}' — has the seed migration run?");
        });
    }

    /**
     * The ledger account for a cash till, creating it (as a 1010 child) the
     * first time it's needed — covers a till created after the initial
     * chart-of-accounts seed, which wouldn't otherwise have one yet. The one
     * place this lookup/creation happens, so nothing else reimplements it.
     */
    public static function forCashTill(CashAccount $till): self
    {
        if ($till->chart_of_account_id) {
            return $till->chartOfAccount;
        }
        $parent = static::system('1010');
        $account = static::create([
            'code' => "1010-{$till->id}",
            'name' => "Cash in Hand - {$till->name}",
            'account_type' => 'Asset',
            'normal_balance' => 'Debit',
            'parent_id' => $parent->id,
            'is_system' => false,
            'status' => $till->status ?? 'Active',
        ]);
        $till->update(['chart_of_account_id' => $account->id]);

        return $account;
    }

    /** Bank equivalent of forCashTill() — see its docblock. */
    public static function forBankAccount(BankAccount $bank): self
    {
        if ($bank->chart_of_account_id) {
            return $bank->chartOfAccount;
        }
        $parent = static::system('1020');
        $account = static::create([
            'code' => "1020-{$bank->id}",
            'name' => "Bank - {$bank->name}",
            'account_type' => 'Asset',
            'normal_balance' => 'Debit',
            'parent_id' => $parent->id,
            'is_system' => false,
            'status' => $bank->status ?? 'Active',
        ]);
        $bank->update(['chart_of_account_id' => $account->id]);

        return $account;
    }

    /** Petty cash equivalent of forCashTill() — see its docblock. Parent is 1015 "Petty Cash Floats", kept apart from 1010 so a float's balance never mixes with the main tills. */
    public static function forPettyCashFund(PettyCashFund $fund): self
    {
        if ($fund->chart_of_account_id) {
            return $fund->chartOfAccount;
        }
        $parent = static::system('1015');
        $account = static::create([
            'code' => "1015-{$fund->id}",
            'name' => "Petty Cash - {$fund->name}",
            'account_type' => 'Asset',
            'normal_balance' => 'Debit',
            'parent_id' => $parent->id,
            'is_system' => false,
            'status' => $fund->status ?? 'Active',
        ]);
        $fund->update(['chart_of_account_id' => $account->id]);

        return $account;
    }

    /**
     * Every account's {total_debit, total_credit, balance} as of a date (all
     * time if null), from every journal_lines row — there is no cached balance
     * column anywhere, this is the one query every report (Trial Balance,
     * Balance Sheet, P&L, the account balance shown on Chart of Accounts)
     * computes from, so they can never disagree with each other.
     *
     * Both Posted AND Reversed entries are counted. A Reversed entry always has
     * a paired reversal entry (LedgerPoster::reverse() posts the mirror before
     * flipping the original to Reversed, atomically), so the pair nets to zero
     * — the reversal cancels the original when both are summed. Excluding the
     * Reversed original while keeping its Posted reversal (the old behaviour)
     * left every account at the *opposite* of the reversed transaction instead
     * of back at zero. There is no draft/void state in journal_entries — a
     * staged manual entry lives in manual_journal_entries until approved — so
     * every row here is a real, final posting that must count.
     *
     * $fromDate additionally floors the range — Trial Balance/Balance Sheet
     * leave it null (a cumulative balance since the account was opened); P&L
     * passes the period's start, since income/expense activity is meaningful
     * only within a range, not "cumulative since forever."
     *
     * @return \Illuminate\Support\Collection<int, object{account_id:int, total_debit:string, total_credit:string, balance:string}>
     */
    private static array $balancesCache = [];

    public static function balancesAsOf(?string $asOfDate = null, ?string $fromDate = null, ?array $branchIds = null): \Illuminate\Support\Collection
    {
        // Per-request memoization — a single Trial Balance/P&L/Balance Sheet/
        // Cash Flow request calls this with the same (asOfDate, fromDate) pair
        // several times over (P&L alone calls it 4x for the same range, once
        // each for cash/accrual figures and cash/accrual waterfall), and each
        // call is a full journal_lines scan. Safe as a static array: cleared
        // per PHP-FPM/CLI request, never shared across requests.
        $key = ($asOfDate ?? '') . '|' . ($fromDate ?? '') . '|' . ($branchIds === null ? '*' : implode(',', $branchIds));
        if (isset(self::$balancesCache[$key])) {
            return self::$balancesCache[$key];
        }

        $totals = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($asOfDate, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $asOfDate))
            ->when($fromDate, fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $fromDate))
            // null = every branch; an array limits the figures to entries tagged with those branches.
            ->when($branchIds !== null, fn ($q) => $q->whereIn('journal_entries.branch_id', $branchIds))
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) as total_debit, SUM(journal_lines.credit) as total_credit')
            ->groupBy('journal_lines.account_id')
            ->get()
            ->keyBy('account_id');

        $balances = static::query()->get(['id', 'normal_balance'])->map(function (self $account) use ($totals) {
            $row = $totals->get($account->id);
            $debit = $row->total_debit ?? '0.00';
            $credit = $row->total_credit ?? '0.00';
            $balance = $account->normal_balance === 'Debit' ? bcsub($debit, $credit, 2) : bcsub($credit, $debit, 2);

            return (object) ['account_id' => $account->id, 'total_debit' => $debit, 'total_credit' => $credit, 'balance' => $balance];
        })->keyBy('account_id');

        return self::$balancesCache[$key] = $balances;
    }

    /** Clears the per-request balance memo — for tests, where one long-lived
     *  PHP process runs many isolated cases and the static cache would
     *  otherwise carry a previous test's figures into the next. */
    public static function flushBalancesCache(): void
    {
        self::$balancesCache = [];
    }
}
