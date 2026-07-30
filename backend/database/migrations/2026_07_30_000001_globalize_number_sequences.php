<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reference numbers (loan codes, payment receipts, FD numbers, ...) stop
 * being split by branch — NumberGenerator::next() now keys the {SEQ} counter
 * on (module, loan type/product, year) only, one company-wide series, so two
 * branches saving at the same moment can never both land on e.g. "00001".
 * {BRANCH} in the printed number stays cosmetic (which branch issued it).
 *
 * This one-time data migration seeds each such key's new global counter
 * (branch_id=0 row) with the HIGHEST last_sequence any branch had already
 * reached for it, so the switch-over continues on from there instead of
 * restarting at 1 and re-issuing a sequence number some branch already used
 * this year. The old per-branch rows are left in place, untouched, purely as
 * historical record — NumberGenerator no longer reads or writes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('number_sequences')
            ->where('branch_id', '!=', 0)
            ->select('module', 'loan_type_id', 'funding_product_id', 'year')
            ->selectRaw('MAX(last_sequence) as max_sequence')
            ->groupBy('module', 'loan_type_id', 'funding_product_id', 'year')
            ->get();

        foreach ($groups as $g) {
            $key = [
                'module' => $g->module,
                'branch_id' => 0,
                'loan_type_id' => $g->loan_type_id,
                'funding_product_id' => $g->funding_product_id,
                'year' => $g->year,
            ];
            $existing = DB::table('number_sequences')->where($key)->first();
            if ($existing) {
                if ($existing->last_sequence < $g->max_sequence) {
                    DB::table('number_sequences')->where('id', $existing->id)
                        ->update(['last_sequence' => $g->max_sequence, 'updated_at' => now()]);
                }
            } else {
                DB::table('number_sequences')->insert($key + [
                    'last_sequence' => $g->max_sequence,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Not meaningfully reversible: this only raises/creates the global
        // (branch_id=0) rows from the per-branch max at the time it ran — the
        // old per-branch rows it read from are left untouched above, so no
        // data is destroyed, but once new numbers are issued against the
        // global counter there's no "per-branch" state left to revert to.
    }
};
