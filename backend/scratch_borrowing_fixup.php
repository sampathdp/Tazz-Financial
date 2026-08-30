<?php

/**
 * ONE-OFF cleanup after the Borrowings feature build. Run once, then delete:
 *   php scratch_borrowing_fixup.php
 *
 * Does two things:
 *  1. Removes journal entries / lines / document-number rows left behind by the
 *     build-time smoke test (source_type Borrowing / BorrowingTransaction, and
 *     the BRW / BRWV reference numbers). These were reversed at the time but the
 *     reversal entries still linger.
 *  2. Ensures chart-of-accounts codes 2200 / 2210 exist (the seed migration
 *     originally tried 2100 / 2110, which were already taken).
 */
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$ids = DB::table('journal_entries')->whereIn('source_type', ['Borrowing', 'BorrowingTransaction'])->pluck('id');
DB::table('journal_lines')->whereIn('journal_entry_id', $ids)->delete();
DB::table('journal_entries')->whereIn('id', $ids)->delete();
DB::table('document_number_log')->whereIn('source_type', ['Borrowing', 'BorrowingTransaction'])->delete();
DB::table('document_number_log')->where(fn ($q) => $q->where('generated_number', 'like', '%-BRW-%')->orWhere('generated_number', 'like', '%-BRWV-%'))->delete();
echo "removed {$ids->count()} smoke-test journal entries\n";

foreach ([
    ['2200', 'Borrowings - Party / Institutional', 'Liability', 'Credit'],
    ['2210', 'Bank Overdrafts', 'Liability', 'Credit'],
    ['2130', 'Re-Pledged Pawning Liability', 'Liability', 'Credit'],
    ['5110', 'Interest Expense - Borrowings', 'Expense', 'Debit'],
] as [$code, $name, $type, $nb]) {
    if (DB::table('chart_of_accounts')->where('code', $code)->exists()) {
        echo "$code already present\n";
        continue;
    }
    DB::table('chart_of_accounts')->insert([
        'code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $nb,
        'parent_id' => null, 'is_system' => true, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    echo "inserted $code $name\n";
}

// Reset the borrowing reference sequences so the first real borrowing starts at 1.
DB::table('number_sequences')->whereIn('module', ['borrowing', 'borrowing_txn'])->update(['last_sequence' => 0]);
echo "borrowing sequences reset\n";
echo "done — you can delete this file now.\n";
