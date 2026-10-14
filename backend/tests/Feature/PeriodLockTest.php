<?php

namespace Tests\Feature;

use App\Exceptions\FinancePostingException;
use App\Models\ChartOfAccount;
use App\Models\FinanceSetting;
use App\Models\User;
use App\Support\LedgerPoster;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Audit finding C-14: a closed period must reject new ledger entries; there was no lock at all. */
class PeriodLockTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin'), 'sanctum');
    }

    private function entryOn(string $date)
    {
        return LedgerPoster::post([
            ['account_id' => ChartOfAccount::system('5010')->id, 'debit' => '5.00'],
            ['account_id' => ChartOfAccount::system('3015')->id, 'credit' => '5.00'],
        ], $date, ['source_type' => 'ManualAdjustment', 'description' => 'lock test']);
    }

    public function test_entries_on_or_before_the_lock_date_are_refused_and_later_ones_accepted(): void
    {
        FinanceSetting::put('ledger_locked_through', '2031-12-31');
        try {
            $this->entryOn('2031-06-01');
            $this->fail('an entry inside the locked period must be refused');
        } catch (FinancePostingException $e) {
            $this->assertStringContainsString('2031-12-31', $e->getMessage());
        }
        $this->assertSame('Posted', $this->entryOn('2032-01-01')->status);
    }

    public function test_the_lock_date_is_boundary_inclusive(): void
    {
        FinanceSetting::put('ledger_locked_through', '2031-12-31');
        $this->expectException(FinancePostingException::class);
        $this->entryOn('2031-12-31');
    }

    public function test_reversing_an_entry_is_dated_today_so_it_still_works_when_the_original_period_is_locked(): void
    {
        $entry = $this->entryOn('2031-03-01');
        FinanceSetting::put('ledger_locked_through', '2031-12-31');
        $reversal = LedgerPoster::reverse($entry->fresh(), 'undo');
        $this->assertSame('Posted', $reversal->status);
    }

    public function test_lock_date_can_be_set_and_cleared_through_the_settings_api(): void
    {
        $this->putJson('/api/finance-settings', ['settings' => ['ledger_locked_through' => '2031-12-31']])->assertOk();
        Cache::flush();
        $this->assertSame('2031-12-31', FinanceSetting::get('ledger_locked_through'));
        $this->putJson('/api/finance-settings', ['settings' => ['ledger_locked_through' => 'not-a-date']])->assertStatus(422);
        $this->putJson('/api/finance-settings', ['settings' => ['ledger_locked_through' => null]])->assertOk();
        Cache::flush();
        $this->assertEmpty(FinanceSetting::get('ledger_locked_through'));
    }
}
