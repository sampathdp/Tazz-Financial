<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\FixedAssetDisposal;
use App\Models\JournalEntry;

/**
 * Bridges Fixed Assets into the general ledger, on the same primitives as the other posters.
 *   - acquisition:  Dr category cost account / Cr the paying till or bank (money moves via FinancePostingService);
 *                   an asset already owned at go-live is Dr cost / Cr 1390 (opening depreciation) / Cr 3015 (net).
 *   - depreciation: Dr 5450 Depreciation Expense / Cr 1390 Accumulated Depreciation, one entry per asset per month.
 *   - disposal:     Dr proceeds account + Dr 1390 + Dr 5460 loss / Cr cost + Cr 4940 gain.
 * Every call must run inside the caller's DB transaction.
 */
class FixedAssetLedgerPoster
{
    /** @return array{0:string,1:int,2:ChartOfAccount} account type, account id, GL leaf */
    public static function resolveSource(string $method, ?int $cashId, ?int $bankId): array
    {
        if ($method === 'Cash') {
            $cash = CashAccount::findOrFail($cashId);

            return ['cash', (int) $cash->id, ChartOfAccount::forCashTill($cash)];
        }
        $bank = BankAccount::findOrFail($bankId);

        return ['bank', (int) $bank->id, ChartOfAccount::forBankAccount($bank)];
    }

    public static function postAcquisition(FixedAsset $asset, ?int $userId): void
    {
        $category = $asset->category;
        $cost = (string) $asset->cost;
        $date = $asset->purchase_date->toDateString();
        $description = "Fixed asset acquired - {$asset->code} {$asset->name}";

        if ($asset->acquisition_type === 'Opening') {
            $acc = (string) $asset->accumulated_depreciation;
            $net = bcsub($cost, $acc, 2);
            $lines = [['account_id' => $category->asset_account_id, 'debit' => $cost, 'credit' => 0]];
            if (bccomp($acc, '0', 2) > 0) {
                $lines[] = ['account_id' => $category->accumulated_depreciation_account_id, 'debit' => 0, 'credit' => $acc];
            }
            if (bccomp($net, '0', 2) > 0) {
                $lines[] = ['account_id' => ChartOfAccount::system('3015')->id, 'debit' => 0, 'credit' => $net];
            }
            $entry = LedgerPoster::post($lines, $date, [
                'source_type' => 'FixedAsset', 'source_id' => $asset->id, 'branch_id' => $asset->branch_id,
                'description' => "Opening balance - {$asset->code} {$asset->name}", 'posted_by' => $userId,
            ]);
            $asset->update(['journal_entry_id' => $entry->id]);

            return;
        }

        [$srcType, $srcId, $leaf] = self::resolveSource($asset->payment_method, $asset->cash_account_id, $asset->bank_account_id);
        $txn = FinancePostingService::execute([
            'txn_type' => 'EXPENSE_PAYMENT',
            'amount' => $cost,
            'entry_date' => $date,
            'branch_id' => $asset->branch_id,
            'movements' => [['account_type' => $srcType, 'account_id' => $srcId, 'delta' => '-' . $cost]],
            'gl_lines' => [
                ['account_id' => $category->asset_account_id, 'debit' => $cost, 'credit' => 0, 'description' => $asset->name],
                ['account_id' => $leaf->id, 'debit' => 0, 'credit' => $cost],
            ],
            'gl_source_type' => 'FixedAsset',
            'gl_source_id' => $asset->id,
            'gl_description' => $description,
            'description' => $description,
            'source_module' => 'FixedAsset',
            'source_id' => $asset->id,
            'idempotency_key' => "fixed-asset-purchase-{$asset->id}",
            'executed_by' => $userId,
        ]);
        $asset->update(['journal_entry_id' => $txn->journal_entry_id, 'finance_transaction_id' => $txn->id]);
    }

    /** Undoes a purchase or opening entry — only used when an asset with no depreciation is deleted. */
    public static function reverseAcquisition(FixedAsset $asset, ?int $userId): void
    {
        if ($asset->finance_transaction_id) {
            FinancePostingService::reverse(FinanceTransaction::findOrFail($asset->finance_transaction_id), "Fixed asset {$asset->code} deleted", $userId);
        } elseif ($asset->journal_entry_id) {
            LedgerPoster::reverse(JournalEntry::findOrFail($asset->journal_entry_id), "Fixed asset {$asset->code} deleted", $userId);
        }
    }

    public static function postDepreciation(FixedAsset $asset, string $period, string $periodEnd, string $amount, ?int $userId): FixedAssetDepreciation
    {
        $row = FixedAssetDepreciation::create([
            'fixed_asset_id' => $asset->id, 'period' => $period, 'period_end' => $periodEnd,
            'amount' => $amount, 'posted_by' => $userId,
        ]);
        $category = $asset->category;
        $entryDate = min($periodEnd, now()->toDateString());
        $entry = LedgerPoster::post([
            ['account_id' => $category->expense_account_id, 'debit' => $amount, 'credit' => 0],
            ['account_id' => $category->accumulated_depreciation_account_id, 'debit' => 0, 'credit' => $amount],
        ], $entryDate, [
            'source_type' => 'FixedAssetDepreciation', 'source_id' => $row->id, 'branch_id' => $asset->branch_id,
            'description' => "Depreciation {$period} - {$asset->code} {$asset->name}", 'posted_by' => $userId,
        ]);
        $row->update(['journal_entry_id' => $entry->id]);

        return $row;
    }

    /**
     * @param array{disposal_date:string, proceeds:numeric, payment_method?:?string, cash_account_id?:?int, bank_account_id?:?int, notes?:?string} $spec
     */
    public static function postDisposal(FixedAsset $asset, array $spec, ?int $userId): FixedAssetDisposal
    {
        $category = $asset->category;
        $cost = (string) $asset->cost;
        $acc = (string) $asset->accumulated_depreciation;
        $book = bcsub($cost, $acc, 2);
        $proceeds = number_format((float) $spec['proceeds'], 2, '.', '');
        $gainLoss = bcsub($proceeds, $book, 2);
        $date = $spec['disposal_date'];
        $description = "Fixed asset disposed - {$asset->code} {$asset->name}";
        $hasProceeds = bccomp($proceeds, '0', 2) > 0;

        $lines = [];
        $movements = [];
        if ($hasProceeds) {
            [$srcType, $srcId, $leaf] = self::resolveSource($spec['payment_method'], $spec['cash_account_id'] ?? null, $spec['bank_account_id'] ?? null);
            $lines[] = ['account_id' => $leaf->id, 'debit' => $proceeds, 'credit' => 0];
            $movements[] = ['account_type' => $srcType, 'account_id' => $srcId, 'delta' => $proceeds];
        }
        if (bccomp($acc, '0', 2) > 0) {
            $lines[] = ['account_id' => $category->accumulated_depreciation_account_id, 'debit' => $acc, 'credit' => 0];
        }
        if (bccomp($gainLoss, '0', 2) < 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('5460')->id, 'debit' => bcmul($gainLoss, '-1', 2), 'credit' => 0];
        }
        $lines[] = ['account_id' => $category->asset_account_id, 'debit' => 0, 'credit' => $cost];
        if (bccomp($gainLoss, '0', 2) > 0) {
            $lines[] = ['account_id' => ChartOfAccount::system('4940')->id, 'debit' => 0, 'credit' => $gainLoss];
        }

        $txnId = null;
        if ($hasProceeds) {
            $txn = FinancePostingService::execute([
                'txn_type' => 'CASH_IN',
                'amount' => $proceeds,
                'entry_date' => $date,
                'branch_id' => $asset->branch_id,
                'movements' => $movements,
                'gl_lines' => $lines,
                'gl_source_type' => 'FixedAssetDisposal',
                'gl_source_id' => $asset->id,
                'gl_description' => $description,
                'description' => $description,
                'source_module' => 'FixedAssetDisposal',
                'source_id' => $asset->id,
                'idempotency_key' => "fixed-asset-disposal-{$asset->id}",
                'executed_by' => $userId,
            ]);
            $entryId = $txn->journal_entry_id;
            $txnId = $txn->id;
        } else {
            $entryId = LedgerPoster::post($lines, $date, [
                'source_type' => 'FixedAssetDisposal', 'source_id' => $asset->id, 'branch_id' => $asset->branch_id,
                'description' => $description, 'posted_by' => $userId,
            ])->id;
        }

        return FixedAssetDisposal::create([
            'fixed_asset_id' => $asset->id,
            'disposal_date' => $date,
            'proceeds' => $proceeds,
            'payment_method' => $hasProceeds ? $spec['payment_method'] : null,
            'cash_account_id' => $hasProceeds && $spec['payment_method'] === 'Cash' ? ($spec['cash_account_id'] ?? null) : null,
            'bank_account_id' => $hasProceeds && $spec['payment_method'] !== 'Cash' ? ($spec['bank_account_id'] ?? null) : null,
            'book_value' => $book,
            'gain_loss' => $gainLoss,
            'journal_entry_id' => $entryId,
            'finance_transaction_id' => $txnId,
            'notes' => $spec['notes'] ?? null,
            'disposed_by' => $userId,
        ]);
    }
}
