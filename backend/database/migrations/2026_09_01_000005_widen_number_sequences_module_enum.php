<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixes a latent numbering bug found during the Finance module work.
 *
 * number_sequences.module was still the original narrow enum
 * (loan/customer/payment/fd_deposit/fixed_deposit/fd_withdrawal/journal/
 * expense/payment_voucher/speed_draft/speed_draft_payment) even though
 * NumberGenerator has since added modules for petty cash, borrowings,
 * pawning, manual journals and FD part-payments. NumberGenerator::next()
 * uses insertOrIgnore() (= INSERT IGNORE), so an unknown enum value was
 * silently coerced to '' — and MySQL then compares module = 'borrowing'
 * as module = '' too, so every one of those modules was quietly sharing a
 * single counter row (one for year-less formats, one per year for the
 * rest). Their document numbers stayed unique only because their prefixes
 * differ (BRW / PW / MJV / ...), but the {SEQ} values were interleaved and
 * gappy.
 *
 * Fix: widen the enum to every module NumberGenerator actually declares,
 * then give each previously-coerced module its own counter row, seeded from
 * the shared '' row's current value so no number it issues next can collide
 * with one already handed out (the shared pool's total draw is the ceiling
 * for every prefix in it).
 */
return new class extends Migration
{
    private const ALL_MODULES = [
        'loan', 'customer', 'payment', 'fd_deposit', 'fixed_deposit', 'fd_withdrawal',
        'fd_part_payment', 'journal', 'finance_transaction', 'manual_journal', 'expense',
        'payment_voucher', 'petty_cash_fund', 'petty_cash_replenishment', 'petty_cash_voucher',
        'speed_draft', 'speed_draft_payment', 'pawning', 'pawning_auction', 'borrowing', 'borrowing_txn',
    ];

    private const ORIGINAL_MODULES = [
        'loan', 'customer', 'payment', 'fd_deposit', 'fixed_deposit', 'fd_withdrawal',
        'journal', 'expense', 'payment_voucher', 'speed_draft', 'speed_draft_payment',
        'finance_transaction',
    ];

    /**
     * Modules that were being coerced to '' => whether their NumberGenerator
     * format uses {YEAR} (and so keys its counter row by year). Drives which
     * shared '' row each one continues from.
     */
    private const COERCED_MODULES = [
        'fd_part_payment' => true,
        'manual_journal' => true,
        'petty_cash_fund' => false,
        'petty_cash_replenishment' => true,
        'petty_cash_voucher' => true,
        'pawning' => true,
        'pawning_auction' => true,
        'borrowing' => true,
        'borrowing_txn' => true,
    ];

    public function up(): void
    {
        $this->setEnum(self::ALL_MODULES);

        // The shared '' rows: a year-less one and one per calendar year. Each
        // coerced module continues from whichever matches its format's {YEAR}
        // usage, so the number it issues next can't collide with one already
        // handed out from that shared pool.
        $yearlessShared = DB::table('number_sequences')->where('module', '')->whereNull('year')->first();
        $yearShared = DB::table('number_sequences')->where('module', '')->whereNotNull('year')->get();

        foreach (self::COERCED_MODULES as $module => $usesYear) {
            $sources = $usesYear ? $yearShared : collect(array_filter([$yearlessShared]));
            foreach ($sources as $shared) {
                $already = DB::table('number_sequences')->where('module', $module)
                    ->where(fn ($q) => $shared->year === null ? $q->whereNull('year') : $q->where('year', $shared->year))
                    ->whereNull('branch_id')->whereNull('loan_type_id')->whereNull('funding_product_id')
                    ->exists();
                if ($already) {
                    continue;
                }
                DB::table('number_sequences')->insert([
                    'module' => $module,
                    'branch_id' => null, 'loan_type_id' => null, 'funding_product_id' => null,
                    'year' => $shared->year,
                    'last_sequence' => $shared->last_sequence,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Drop the rows this migration seeded, then narrow the enum back.
        DB::table('number_sequences')->whereIn('module', array_keys(self::COERCED_MODULES))->delete();
        $this->setEnum(self::ORIGINAL_MODULES);
    }

    private function setEnum(array $modules): void
    {
        $list = collect($modules)->map(fn ($m) => "'".$m."'")->implode(',');
        DB::statement("ALTER TABLE `number_sequences` MODIFY `module` ENUM({$list}) NOT NULL");
    }
};
