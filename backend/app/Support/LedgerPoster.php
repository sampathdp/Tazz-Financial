<?php

namespace App\Support;

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
     * @param array{reference_type?:?string, reference_id?:?int, description?:?string, branch_id?:?int, posted_by?:?int} $meta
     */
    public static function post(array $lines, string $entryDate, array $meta = []): JournalEntry
    {
        if (count($lines) < 2) {
            throw new \InvalidArgumentException('A journal entry needs at least two lines.');
        }

        $totalDebit = '0';
        $totalCredit = '0';
        foreach ($lines as $line) {
            $totalDebit = bcadd($totalDebit, (string) ($line['debit'] ?? 0), 2);
            $totalCredit = bcadd($totalCredit, (string) ($line['credit'] ?? 0), 2);
        }
        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw new \RuntimeException("Journal entry does not balance: debit {$totalDebit} != credit {$totalCredit}.");
        }

        $code = NumberGenerator::next('journal');

        $entry = JournalEntry::create([
            'code' => $code,
            'entry_date' => $entryDate,
            'reference_type' => $meta['reference_type'] ?? null,
            'reference_id' => $meta['reference_id'] ?? null,
            'description' => $meta['description'] ?? null,
            'branch_id' => $meta['branch_id'] ?? null,
            'posted_by' => $meta['posted_by'] ?? null,
            'posted_at' => now(),
            'status' => 'Posted',
        ]);
        NumberGenerator::attach($code, $entry);

        foreach ($lines as $line) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account_id'],
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'description' => $line['description'] ?? null,
            ]);
        }

        return $entry;
    }

    /**
     * Reverses a posted entry with a new, mirrored entry (debit/credit swapped
     * on every line) — never edits or deletes the original, so the audit
     * trail shows both the mistake and its correction.
     */
    public static function reverse(JournalEntry $entry, ?string $reason = null, ?int $userId = null): JournalEntry
    {
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
            'reference_type' => $entry->reference_type,
            'reference_id' => $entry->reference_id,
            'description' => $reason ?? "Reversal of {$entry->code}",
            'branch_id' => $entry->branch_id,
            'posted_by' => $userId,
        ]);
        $reversal->update(['reversal_of_id' => $entry->id]);
        $entry->update(['status' => 'Reversed']);

        return $reversal;
    }
}
