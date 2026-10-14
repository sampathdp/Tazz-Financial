<?php

namespace App\Support;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\NumberGenerator;

/**
 * The single writer for the general ledger — the only code allowed to insert
 * a journal_entries/journal_lines row. Every business event that touches the
 * chart of accounts (an expense paid, eventually a loan disbursed or
 * collected — see the cash/bank migration guide) goes through post() here
 * rather than each module reimplementing "write a balanced double-entry
 * posting" on its own.
 *
 * Like NumberGenerator, this does NOT open its own DB transaction — it must
 * be called from inside the caller's, since a posting is always one step of
 * a larger business transaction (e.g. marking a PaymentVoucher Paid).
 */
class LedgerPoster
{
    /**
     * @param array<int, array{account_id:int, debit?:float|string, credit?:float|string, description?:?string}> $lines
     * @param array{source_type?:?string, source_id?:?int, description?:?string, branch_id?:?int, posted_by?:?int} $meta
     */
    public static function post(array $lines, string $entryDate, array $meta = []): JournalEntry
    {
        if (count($lines) < 2) {
            throw new \InvalidArgumentException('A journal entry needs at least two lines.');
        }

        $totalDebit = '0';
        $totalCredit = '0';
        foreach ($lines as $i => $line) {
            $debit = (string) ($line['debit'] ?? 0);
            $credit = (string) ($line['credit'] ?? 0);
            // Each line is strictly one-sided and non-zero — mirrors the
            // chk_journal_lines_one_side DB constraint (see the
            // 2026_09_02 restore migration). Caught here so a bad line fails
            // with a clear message instead of a raw driver exception, and so
            // the guard still holds if the DB constraint is ever missing again.
            if (bccomp($debit, '0', 2) < 0 || bccomp($credit, '0', 2) < 0) {
                throw new \RuntimeException("Journal line {$i} has a negative amount.");
            }
            $debitPositive = bccomp($debit, '0', 2) > 0;
            $creditPositive = bccomp($credit, '0', 2) > 0;
            if ($debitPositive === $creditPositive) {
                throw new \RuntimeException("Journal line {$i} must have exactly one of debit / credit positive (got debit {$debit}, credit {$credit}).");
            }
            $totalDebit = bcadd($totalDebit, $debit, 2);
            $totalCredit = bcadd($totalCredit, $credit, 2);
        }
        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw new \RuntimeException("Journal entry does not balance: debit {$totalDebit} != credit {$totalCredit}.");
        }

        // Every account must exist, be postable (not a header that only groups child accounts) and be
        // active. A reversal of an already-posted entry is exempt: it must always be able to undo history,
        // even after an account was later deactivated.
        if (empty($meta['is_reversal'])) {
            // Period lock: nothing may be posted on or before the closed-through date (Finance Settings).
            $lockedThrough = \App\Models\FinanceSetting::get('ledger_locked_through');
            if ($lockedThrough && substr($entryDate, 0, 10) <= $lockedThrough) {
                throw new \App\Exceptions\FinancePostingException("The books are closed through {$lockedThrough}, so an entry dated {$entryDate} cannot be posted. Use a date after the close, or ask a finance administrator to move the lock date.");
            }

            $ids = collect($lines)->pluck('account_id')->unique()->values();
            $accounts = ChartOfAccount::whereIn('id', $ids)->get(['id', 'code', 'status', 'is_postable'])->keyBy('id');
            foreach ($ids as $id) {
                $account = $accounts->get($id);
                if (!$account) {
                    throw new \RuntimeException("Account {$id} does not exist.");
                }
                if ($account->is_postable !== null && !$account->is_postable) {
                    throw new \RuntimeException("Account {$account->code} is a header account and cannot be posted to; use one of its child accounts.");
                }
                if ($account->status !== 'Active') {
                    throw new \RuntimeException("Account {$account->code} is inactive and cannot be posted to.");
                }
            }
        }

        // An entry with no branch has no home in any branch report, so fall back to the acting user's branch.
        $branchId = $meta['branch_id'] ?? \Illuminate\Support\Facades\Auth::user()?->branch_id;

        // Inter-branch accounting: lines that move another branch's cash or bank account are split into a
        // companion entry for that branch, joined by 1400 / 2400, so every branch still balances on its own.
        $companions = [];
        if ($branchId && empty($meta['is_reversal'])) {
            [$lines, $companions] = self::splitByBranch($lines, (int) $branchId);
        }

        $entry = self::write($lines, $entryDate, $meta, $branchId, null, null);
        foreach ($companions as $branch => $companionLines) {
            self::write($companionLines, $entryDate, $meta, (int) $branch, (int) $branchId, $entry->id);
        }

        return $entry;
    }

    /** Writes one balanced entry and its lines (no validation: callers have already validated). */
    private static function write(array $lines, string $entryDate, array $meta, $branchId, ?int $counterpartyBranchId, ?int $relatedEntryId): JournalEntry
    {
        $code = NumberGenerator::next('journal');

        $entry = JournalEntry::create([
            'code' => $code,
            'entry_date' => $entryDate,
            'source_type' => $meta['source_type'] ?? null,
            'source_id' => $meta['source_id'] ?? null,
            'description' => $meta['description'] ?? null,
            'branch_id' => $branchId,
            'counterparty_branch_id' => $counterpartyBranchId,
            'related_entry_id' => $relatedEntryId,
            'posted_by' => $meta['posted_by'] ?? null,
            'posted_at' => now(),
            'status' => 'Posted',
        ]);
        NumberGenerator::attach($code, $entry);

        foreach ($lines as $line) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'entry_date' => $entryDate,
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'description' => $line['description'] ?? null,
            ]);
        }

        return $entry;
    }

    /**
     * Splits an entry tagged to $branchId when some of its lines are another branch's cash, bank or petty-cash
     * account. For each other branch B: B's lines move to a companion entry; the entry for $branchId and the
     * companion each get one clearing line so both balance on their own.
     *   B's cash went out (net credit C): $branchId Cr 2400 (owes B) / B Dr 1400 (is owed by $branchId)
     *   B's cash came in  (net debit D):  $branchId Dr 1400 (is owed by B) / B Cr 2400 (owes $branchId)
     *
     * @return array{0: array, 1: array<int, array>} [lines for the main entry, [branch id => companion lines]]
     */
    private static function splitByBranch(array $lines, int $branchId): array
    {
        $ids = collect($lines)->pluck('account_id')->unique()->values();
        $owner = [];
        foreach ([\App\Models\CashAccount::class, \App\Models\BankAccount::class, \App\Models\PettyCashFund::class] as $model) {
            foreach ($model::withoutGlobalScopes()->whereIn('chart_of_account_id', $ids)->whereNotNull('branch_id')->get(['chart_of_account_id', 'branch_id']) as $row) {
                $owner[(int) $row->chart_of_account_id] = (int) $row->branch_id;
            }
        }
        $main = [];
        $foreign = [];
        foreach ($lines as $line) {
            $b = $owner[(int) $line['account_id']] ?? null;
            if ($b && $b !== $branchId) {
                $foreign[$b][] = $line;
            } else {
                $main[] = $line;
            }
        }
        if (!$foreign) {
            return [$lines, []];
        }

        $receivable = ChartOfAccount::system('1400')->id;
        $payable = ChartOfAccount::system('2400')->id;
        $companions = [];
        foreach ($foreign as $b => $bLines) {
            $net = '0';
            foreach ($bLines as $l) {
                $net = bcadd(bcsub($net, (string) ($l['credit'] ?? 0), 2), (string) ($l['debit'] ?? 0), 2);
            }
            $label = "Inter-branch with branch {$b}";
            $companion = $bLines;
            if (bccomp($net, '0', 2) > 0) {
                $main[] = ['account_id' => $receivable, 'debit' => $net, 'credit' => 0, 'description' => $label];
                $companion[] = ['account_id' => $payable, 'debit' => 0, 'credit' => $net, 'description' => "Inter-branch with branch {$branchId}"];
            } elseif (bccomp($net, '0', 2) < 0) {
                $abs = bcmul($net, '-1', 2);
                $main[] = ['account_id' => $payable, 'debit' => 0, 'credit' => $abs, 'description' => $label];
                $companion[] = ['account_id' => $receivable, 'debit' => $abs, 'credit' => 0, 'description' => "Inter-branch with branch {$branchId}"];
            }
            $companions[$b] = $companion;
        }

        return [$main, $companions];
    }

    /**
     * Reverses a posted entry with a new, mirrored entry (debit/credit swapped
     * on every line) — never edits or deletes the original, so the audit
     * trail shows both the mistake and its correction.
     */
    public static function reverse(JournalEntry $entry, ?string $reason = null, ?int $userId = null): JournalEntry
    {
        $entry->refresh();
        // A companion entry may already have been reversed together with its main entry; callers that loop over
        // every entry of one source can then reach it a second time, which is a no-op, not an error.
        if ($entry->status === 'Reversed' && $entry->related_entry_id) {
            return JournalEntry::where('reversal_of_id', $entry->id)->firstOrFail();
        }
        if ($entry->status !== 'Posted') {
            throw new \RuntimeException('Only a posted journal entry can be reversed.');
        }

        $lines = $entry->lines()->get()->map(fn (JournalLine $line) => [
            'account_id' => $line->account_id,
            'debit' => $line->credit,
            'credit' => $line->debit,
            'description' => $line->description,
        ])->all();

        $reversal = self::post($lines, now()->toDateString(), [
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'description' => $reason ?? "Reversal of {$entry->code}",
            'branch_id' => $entry->branch_id,
            'posted_by' => $userId,
            'is_reversal' => true,
        ]);
        // A reversed companion keeps its counterparty so the inter-branch balances net back to zero.
        $reversal->update(['reversal_of_id' => $entry->id, 'counterparty_branch_id' => $entry->counterparty_branch_id]);
        $entry->update(['status' => 'Reversed']);

        // Reversing a main entry reverses its inter-branch companions with it.
        if (!$entry->related_entry_id) {
            JournalEntry::where('related_entry_id', $entry->id)->where('status', 'Posted')->get()
                ->each(fn (JournalEntry $companion) => self::reverse($companion, $reason, $userId));
        }

        return $reversal;
    }
}
