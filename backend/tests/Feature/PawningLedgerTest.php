<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Models\Pawning;
use App\Models\PawningType;
use App\Models\User;
use App\Support\PawningInterestCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan: docs/audit/test-plans/pawning.md
 * Findings: docs/audit/failures.md
 *
 * Persistent DB + DatabaseTransactions rollback -> balance assertions are
 * baseline->delta on shared control accounts (1120/4025/1190/2110/4900);
 * absolute on the per-test till leaf (fresh, zero history). Every expected
 * figure is hand-computed in the test body.
 */
class PawningLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $branchId;

    private CashAccount $till;

    private ChartOfAccount $tillLeaf;

    private int $customerId;

    private int $articleTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '1120')->exists()) {
            $this->markTestSkipped('Pawning chart of accounts not seeded.');
        }
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first()
            ?? $this->markTestSkipped('No admin user seeded.');

        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'PWN Branch', 'code' => 'PWN' . Str::random(3),
            'branch_type' => 'Branch', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->till = CashAccount::create([
            'code' => 'PWN-CASH-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain',
            'name' => 'PWN Till', 'opening_balance' => 0, 'current_balance' => '5000000.00', 'status' => 'Active',
        ]);
        $this->tillLeaf = ChartOfAccount::forCashTill($this->till->fresh());

        $this->customerId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7),
            'full_name' => 'PWN Customer', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->articleTypeId = DB::table('pawning_article_types')->insertGetId([
            'name' => 'Gold 22kt ' . Str::random(5), 'code' => 'G22' . Str::random(3), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum');
    }

    // ---------------- helpers ----------------

    private function pawningType(array $overrides = []): PawningType
    {
        return PawningType::create(array_merge([
            'name' => 'PWN Scheme', 'code' => 'PS' . Str::random(4),
            'normal_rate_percent' => '3.000', 'special_rate_percent' => '2.000', 'special_rate_days' => 10,
            'cycle_days' => 30, 'ltv_percent' => '80.00', 'grace_period_days' => 5,
            'auction_eligible_days' => 30, 'auction_notice_days' => 7, 'status' => 'Active',
        ], $overrides));
    }

    /** POST /api/pawnings; returns the created Pawning. */
    private function createTicket(PawningType $type, float $principal, array $articles, ?string $startDate = null): Pawning
    {
        $payload = [
            'branch_id' => $this->branchId,
            'customer_id' => $this->customerId,
            'pawning_type_id' => $type->id,
            'principal_amount' => $principal,
            'start_date' => $startDate ?? now()->toDateString(),
            'articles' => array_map(fn ($a) => array_merge([
                'article_type_id' => $this->articleTypeId,
                'tested_karat' => '22.00',
            ], $a), $articles),
        ];
        $res = $this->postJson('/api/pawnings', $payload);
        $res->assertStatus(201);

        return Pawning::findOrFail($res->json('id'));
    }

    private function glBalance(int $accountId): string
    {
        ChartOfAccount::flushBalancesCache();
        $r = ChartOfAccount::balancesAsOf(now()->addYear()->toDateString())->get($accountId);

        return $r ? (string) $r->balance : '0.00';
    }

    private function acct(string $code): int
    {
        return ChartOfAccount::where('code', $code)->value('id');
    }

    /** journal_lines for a given source, keyed by account_id => ['debit'=>, 'credit'=>]. */
    private function linesFor(string $sourceType, int $sourceId): array
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.source_type', $sourceType)
            ->where('journal_entries.source_id', $sourceId)
            ->get(['journal_lines.account_id', 'journal_lines.debit', 'journal_lines.credit']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->account_id] = ['debit' => (string) $r->debit, 'credit' => (string) $r->credit];
        }

        return $out;
    }

    private function assertBc(string $expected, string $actual, string $msg = ''): void
    {
        $this->assertSame(0, bccomp($expected, $actual, 2), $msg ?: "expected {$expected} got {$actual}");
    }

    // =============================================================
    // PWN-01 — advance calc: appraised = round(net*rate,2); maxLoan = round(appraised*ltv/100,2)
    // =============================================================
    public function test_pwn01_advance_calculation_rounds_at_each_step(): void
    {
        // net 7.315 * rate 14,997.50 = 109,706.7125 -> round 109,706.71
        // maxLoan = round(109,706.71 * 80/100, 2) = round(87,765.368, 2) = 87,765.37
        $type = $this->pawningType(['ltv_percent' => '80.00']);
        $ticket = $this->createTicket($type, 80000.00, [
            ['net_weight_grams' => '7.315', 'gross_weight_grams' => '7.900', 'rate_per_gram' => '14997.50'],
        ]);

        $pd = DB::table('facility_collateral_pawn_details')
            ->join('facility_collateral', 'facility_collateral.id', '=', 'facility_collateral_pawn_details.facility_collateral_id')
            ->where('facility_collateral.credit_facility_id', $ticket->id)
            ->first();

        $this->assertBc('109706.71', (string) $pd->appraised_value);
        $this->assertBc('87765.37', (string) $pd->max_loan_value);
    }

    // =============================================================
    // PWN-02 — disbursement: Dr 1120 / Cr till leaf; till moves; 1120 +principal
    // =============================================================
    public function test_pwn02_disbursement_posts_dr_1120_cr_till_and_moves_the_till(): void
    {
        $c1120 = $this->acct('1120');
        $before1120 = $this->glBalance($c1120);
        $tillCashBefore = (string) $this->till->fresh()->current_balance;

        $type = $this->pawningType();
        $ticket = $this->createTicket($type, 200000.00, [
            ['net_weight_grams' => '20.000', 'gross_weight_grams' => '21.000', 'rate_per_gram' => '15000.00'],
        ]);

        $lines = $this->linesFor('Pawning', $ticket->id);
        $this->assertArrayHasKey($c1120, $lines);
        $this->assertArrayHasKey($this->tillLeaf->id, $lines);
        $this->assertBc('200000.00', $lines[$c1120]['debit']);
        $this->assertBc('200000.00', $lines[$this->tillLeaf->id]['credit']);

        $this->assertBc('200000.00', bcsub($this->glBalance($c1120), $before1120, 2), '1120 delta');
        $this->assertBc(bcsub($tillCashBefore, '200000.00', 2), (string) $this->till->fresh()->current_balance, 'till cash moved');
        $this->assertBc('-200000.00', $this->glBalance($this->tillLeaf->id), 'till leaf GL');

        $ftx = FinanceTransaction::where('source_module', 'Pawning')->where('source_id', $ticket->id)->first();
        $this->assertNotNull($ftx);
        $this->assertSame('DISBURSEMENT', $ftx->txn_type);
        $this->assertBc('200000.00', (string) $ftx->amount);

        $this->assertSame('200000.00', (string) $ticket->fresh()->outstanding_balance_cache);
    }

    // =============================================================
    // PWN-03 — full redemption, normal rate, day 15
    // =============================================================
    public function test_pwn03_full_redemption_at_normal_rate_day_15(): void
    {
        $c1120 = $this->acct('1120');
        $c4025 = $this->acct('4025');
        $before1120 = $this->glBalance($c1120);
        $before4025 = $this->glBalance($c4025);
        $tillCashStart = (string) $this->till->fresh()->current_balance;

        $type = $this->pawningType(); // normal 3%, special 2% <=10d
        $ticket = $this->createTicket($type, 200000.00, [
            ['net_weight_grams' => '20.000', 'gross_weight_grams' => '21.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');

        // Redeem on 2026-03-16 -> daysElapsed 15 > 10 -> normal 3%
        // interest = round(200,000 * 3/100, 2) = 6,000.00 ; total = 206,000.00
        $res = $this->postJson("/api/pawnings/{$ticket->id}/redeem", [
            'redemption_type' => 'Full', 'payment_method' => 'Cash', 'paid_at' => '2026-03-16',
        ])->assertStatus(200);

        $payment = DB::table('facility_payments')->where('credit_facility_id', $ticket->id)->latest('id')->first();
        $lines = $this->linesFor('PawningPayment', $payment->id);

        $this->assertBc('206000.00', $lines[$this->tillLeaf->id]['debit'], 'Dr till leaf');
        $this->assertBc('6000.00', $lines[$c4025]['credit'], 'Cr 4025 interest');
        $this->assertBc('200000.00', $lines[$c1120]['credit'], 'Cr 1120 capital');

        // Ticket-level nets: 1120 back to where it was, 4025 +6,000.
        $this->assertBc('0.00', bcsub($this->glBalance($c1120), $before1120, 2), '1120 nets to zero for the ticket');
        $this->assertBc('6000.00', bcsub($this->glBalance($c4025), $before4025, 2), '4025 +6,000');

        // Cash: -200,000 (disburse) + 206,000 (redeem) = +6,000 net
        $this->assertBc(bcadd($tillCashStart, '6000.00', 2), (string) $this->till->fresh()->current_balance);
        $this->assertBc('6000.00', $this->glBalance($this->tillLeaf->id), 'till leaf GL net');

        $ticket->refresh();
        $this->assertSame('Closed', $ticket->status);
        $this->assertBc('0.00', (string) $ticket->outstanding_balance_cache);

        $released = DB::table('facility_collateral_pawn_details')
            ->join('facility_collateral', 'facility_collateral.id', '=', 'facility_collateral_pawn_details.facility_collateral_id')
            ->where('facility_collateral.credit_facility_id', $ticket->id)->value('item_status');
        $this->assertSame('Released', $released);
    }

    // =============================================================
    // PWN-04 — special-rate window: day 10 inclusive (2%) vs day 11 (3%)
    // =============================================================
    public function test_pwn04_special_rate_boundary_day_10_vs_day_11(): void
    {
        $type = $this->pawningType(); // special 2% for <=10 days, normal 3%
        $ticket = $this->createTicket($type, 200000.00, [
            ['net_weight_grams' => '20.000', 'gross_weight_grams' => '21.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');
        $ticket->load('pawningDetail.pawningType');

        // Unit: quote() directly on the boundary.
        $day10 = PawningInterestCalculator::quoteFull($ticket, Carbon::parse('2026-03-11')); // 10 days
        $day11 = PawningInterestCalculator::quoteFull($ticket, Carbon::parse('2026-03-12')); // 11 days

        $this->assertSame(10, $day10['daysElapsed']);
        $this->assertTrue($day10['isSpecial']);
        $this->assertBc('2.000', (string) $day10['rate']);
        $this->assertBc('4000.00', (string) $day10['interest']); // round(200,000*2/100,2)

        $this->assertSame(11, $day11['daysElapsed']);
        $this->assertFalse($day11['isSpecial']);
        $this->assertBc('3.000', (string) $day11['rate']);
        $this->assertBc('6000.00', (string) $day11['interest']); // round(200,000*3/100,2)
    }

    // =============================================================
    // PWN-05 — partial redemption: proportional capital split, one article released
    // =============================================================
    public function test_pwn05_partial_redemption_proportional_split(): void
    {
        $c1120 = $this->acct('1120');
        $c4025 = $this->acct('4025');
        $before1120 = $this->glBalance($c1120);

        $type = $this->pawningType();
        // Article A: 20g @ 15,000 -> appraised 300,000 -> maxLoan 240,000
        // Article B: 10g @ 15,000 -> appraised 150,000 -> maxLoan 120,000
        // total maxLoan 360,000
        $ticket = $this->createTicket($type, 200000.00, [
            ['net_weight_grams' => '20.000', 'gross_weight_grams' => '21.000', 'rate_per_gram' => '15000.00'],
            ['net_weight_grams' => '10.000', 'gross_weight_grams' => '11.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');

        $collateral = DB::table('facility_collateral')->where('credit_facility_id', $ticket->id)->orderBy('id')->get();
        // Article B is the 2nd row.
        $articleB = $collateral[1]->id;

        // capital = round(200,000 * 120,000/360,000, 2) = round(66,666.666..., 2) = 66,666.67
        // interest (day 15, normal 3%) = round(66,666.67 * 3/100, 2) = round(2,000.0001, 2) = 2,000.00
        // total = 68,666.67
        $res = $this->postJson("/api/pawnings/{$ticket->id}/redeem", [
            'redemption_type' => 'Partial',
            'facility_collateral_ids' => [$articleB],
            'payment_method' => 'Cash', 'paid_at' => '2026-03-16',
        ])->assertStatus(200);

        $payment = DB::table('facility_payments')->where('credit_facility_id', $ticket->id)->latest('id')->first();
        $lines = $this->linesFor('PawningPayment', $payment->id);

        $this->assertBc('68666.67', $lines[$this->tillLeaf->id]['debit']);
        $this->assertBc('2000.00', $lines[$c4025]['credit']);
        $this->assertBc('66666.67', $lines[$c1120]['credit']);

        $ticket->refresh();
        $this->assertBc('133333.33', (string) $ticket->outstanding_balance_cache); // 200,000 - 66,666.67
        $this->assertSame('Active', $ticket->status);

        $statuses = DB::table('facility_collateral_pawn_details')
            ->join('facility_collateral', 'facility_collateral.id', '=', 'facility_collateral_pawn_details.facility_collateral_id')
            ->where('facility_collateral.credit_facility_id', $ticket->id)
            ->pluck('item_status', 'facility_collateral.id');
        $this->assertSame('Released', $statuses[$articleB]);
        $this->assertSame('Pledged', $statuses[$collateral[0]->id]);
    }

    // =============================================================
    // PWN-06 — renewal: interest settled, capital reduced, due date rolled
    // =============================================================
    public function test_pwn06_renewal_settles_interest_and_reduces_capital_once(): void
    {
        $c1120 = $this->acct('1120');
        $c4025 = $this->acct('4025');
        $before1120 = $this->glBalance($c1120);
        $before4025 = $this->glBalance($c4025);

        $type = $this->pawningType();
        $ticket = $this->createTicket($type, 200000.00, [
            ['net_weight_grams' => '20.000', 'gross_weight_grams' => '21.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');

        // Renew 2026-03-20 (19 days -> normal 3%), capital_payment 50,000
        // interest = round(200,000 * 3/100, 2) = 6,000 ; amount = 56,000
        $res = $this->postJson("/api/pawnings/{$ticket->id}/renew", [
            'capital_payment' => 50000, 'payment_method' => 'Cash', 'paid_at' => '2026-03-20',
        ])->assertStatus(200);

        $payment = DB::table('facility_payments')->where('credit_facility_id', $ticket->id)->latest('id')->first();
        $lines = $this->linesFor('PawningPayment', $payment->id);
        $this->assertBc('56000.00', $lines[$this->tillLeaf->id]['debit']);
        $this->assertBc('6000.00', $lines[$c4025]['credit']);
        $this->assertBc('50000.00', $lines[$c1120]['credit']);

        $ticket->refresh();
        $this->assertBc('150000.00', (string) $ticket->outstanding_balance_cache, 'capital reduced by 50,000, not re-added');
        $this->assertBc('6000.00', bcsub($this->glBalance($c4025), $before4025, 2));

        // due date rolled 30 days from 2026-03-20
        $detail = DB::table('pawning_facility_details')->where('credit_facility_id', $ticket->id)->first();
        $this->assertSame('2026-04-19', Carbon::parse($detail->repay_due_date)->toDateString());
    }

    // =============================================================
    // PWN-07 — multiple tickets, one redeemed, other untouched
    // =============================================================
    public function test_pwn07_multiple_tickets_do_not_cross_contaminate(): void
    {
        $c1120 = $this->acct('1120');
        $type = $this->pawningType();
        $t1 = $this->createTicket($type, 200000.00, [
            ['net_weight_grams' => '20.000', 'gross_weight_grams' => '21.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');
        $t2 = $this->createTicket($type, 80000.00, [
            ['net_weight_grams' => '9.000', 'gross_weight_grams' => '9.500', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');

        $before1120 = $this->glBalance($c1120);
        $t2DueBefore = DB::table('pawning_facility_details')->where('credit_facility_id', $t2->id)->value('repay_due_date');

        $this->postJson("/api/pawnings/{$t1->id}/redeem", [
            'redemption_type' => 'Full', 'payment_method' => 'Cash', 'paid_at' => '2026-03-16',
        ])->assertStatus(200);

        // 1120 dropped by exactly t1's 200,000.
        $this->assertBc('-200000.00', bcsub($this->glBalance($c1120), $before1120, 2));

        $t2->refresh();
        $this->assertSame('Active', $t2->status);
        $this->assertBc('80000.00', (string) $t2->outstanding_balance_cache);
        $this->assertSame(
            Carbon::parse($t2DueBefore)->toDateString(),
            Carbon::parse(DB::table('pawning_facility_details')->where('credit_facility_id', $t2->id)->value('repay_due_date'))->toDateString()
        );
    }

    // =============================================================
    // PWN-08 — Bank/Cheque redemption posts Dr 1030 / Cr split (F-6 fixed)
    // =============================================================
    public function test_pwn08_bank_method_redemption_posts_to_1030(): void
    {
        $c1120 = $this->acct('1120');
        $c4025 = $this->acct('4025');
        $c1030 = $this->acct('1030');
        $type = $this->pawningType();
        $ticket = $this->createTicket($type, 200000.00, [
            ['net_weight_grams' => '20.000', 'gross_weight_grams' => '21.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');

        $before1120 = $this->glBalance($c1120);
        $before4025 = $this->glBalance($c4025);
        $before1030 = $this->glBalance($c1030);
        $tillCashBefore = (string) $this->till->fresh()->current_balance;

        // Redeem on 2026-03-16 (day 15, normal 3%) -> interest 6,000 ; total 206,000
        $this->postJson("/api/pawnings/{$ticket->id}/redeem", [
            'redemption_type' => 'Full', 'payment_method' => 'Bank', 'paid_at' => '2026-03-16',
        ])->assertStatus(200);

        $payment = DB::table('facility_payments')->where('credit_facility_id', $ticket->id)->latest('id')->first();
        $ticket->refresh();
        $this->assertSame('Closed', $ticket->status);

        $lines = $this->linesFor('PawningPayment', $payment->id);
        $this->assertBc('206000.00', $lines[$c1030]['debit'], 'Dr 1030 undeposited funds');
        $this->assertBc('6000.00', $lines[$c4025]['credit'], 'Cr 4025 interest');
        $this->assertBc('200000.00', $lines[$c1120]['credit'], 'Cr 1120 capital');

        $this->assertBc('-200000.00', bcsub($this->glBalance($c1120), $before1120, 2), '1120 relieved');
        $this->assertBc('6000.00', bcsub($this->glBalance($c4025), $before4025, 2), '4025 recognised');
        $this->assertBc('206000.00', bcsub($this->glBalance($c1030), $before1030, 2), '1030 holds the receipt until banked');
        // No physical till movement for a bank receipt.
        $this->assertBc($tillCashBefore, (string) $this->till->fresh()->current_balance, 'till not touched');
    }

    // =============================================================
    // PWN-09 — badly overdue ticket collects the SAME single-cycle interest
    // =============================================================
    public function test_pwn09_overdue_ticket_charges_only_one_cycle_of_interest(): void
    {
        $type = $this->pawningType();
        $ticket = $this->createTicket($type, 200000.00, [
            ['net_weight_grams' => '20.000', 'gross_weight_grams' => '21.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');
        $ticket->load('pawningDetail.pawningType');

        // 15 days late vs 106 days late -> identical interest (flat, single cycle).
        $q15 = PawningInterestCalculator::quoteFull($ticket, Carbon::parse('2026-03-16'));
        $q106 = PawningInterestCalculator::quoteFull($ticket, Carbon::parse('2026-06-15'));

        $this->assertBc('6000.00', (string) $q15['interest']);
        $this->assertBc((string) $q15['interest'], (string) $q106['interest'],
            'documents: a 106-day-overdue ticket collects the same interest as a 15-day one (no extra-cycle charge)');
        $this->assertBc('3.000', (string) $q106['rate']);
    }

    // =============================================================
    // PWN-11 — auction sale, shortfall. Split: the factual entry, plus two
    // finding assertions (F-5 loss not in P&L; F-Risk-3 cash not moved).
    // =============================================================
    /** Shared fixture for the pwn11 cases: a 100,000-capital ticket auctioned for 90,000. */
    private function auctionedForShortfall(): array
    {
        $type = $this->pawningType(['normal_rate_percent' => '9.000', 'special_rate_percent' => null, 'special_rate_days' => null]);
        $ticket = $this->createTicket($type, 100000.00, [
            ['net_weight_grams' => '10.000', 'gross_weight_grams' => '11.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');
        $collateralId = DB::table('facility_collateral')->where('credit_facility_id', $ticket->id)->value('id');
        $auctionId = $this->postJson('/api/pawning-auctions', [
            'branch_id' => $this->branchId, 'scheduled_date' => now()->toDateString(),
        ])->assertStatus(201)->json('id');
        $this->postJson("/api/pawning-auctions/{$auctionId}/items", ['facility_collateral_ids' => [$collateralId]])->assertStatus(201);
        $item = DB::table('pawning_auction_items')->where('pawning_auction_id', $auctionId)->first();

        return [$ticket, $item];
    }

    public function test_pwn11_auction_shortfall_posts_the_documented_lines(): void
    {
        $c1120 = $this->acct('1120');
        $c5300 = $this->acct('5300');
        [, $item] = $this->auctionedForShortfall();

        $this->assertBc('100000.00', (string) $item->outstanding_capital);
        $this->assertBc('9000.00', (string) $item->outstanding_interest);

        $before1120 = $this->glBalance($c1120);

        // hammer 90,000 ; owed 109,000
        // capitalRecovered = min(100,000, 90,000) = 90,000 ; shortfall = 10,000
        // interestRecovered = min(9,000, max(0, 90,000-90,000)) = 0 ; surplus = 0
        $this->postJson("/api/pawning-auction-items/{$item->id}/sale", ['hammer_price' => 90000])->assertStatus(200);

        $lines = $this->linesFor('PawningAuctionItem', $item->id);
        $this->assertBc('90000.00', $lines[$this->tillLeaf->id]['debit'], 'Dr till leaf 90,000');
        $this->assertBc('10000.00', $lines[$c5300]['debit'], 'Dr 5300 shortfall loss 10,000');
        $this->assertBc('100000.00', $lines[$c1120]['credit'], 'Cr 1120 100,000');

        // The entry balances on its own: 90,000 + 10,000 = 100,000.
        $entryId = DB::table('journal_entries')->where('source_type', 'PawningAuctionItem')->where('source_id', $item->id)->value('id');
        $t = DB::table('journal_lines')->where('journal_entry_id', $entryId)->selectRaw('SUM(debit) d, SUM(credit) c')->first();
        $this->assertBc((string) $t->d, (string) $t->c);

        // 1120 (Debit-normal) for the ticket nets to zero: +100,000 disburse, -100,000 sale.
        $this->assertBc('-100000.00', bcsub($this->glBalance($c1120), $before1120, 2));
    }

    /** F-5 fixed: the auction shortfall is expensed to 5300 (P&L), not buried
     *  in the unfunded 1190 contra-asset. */
    public function test_pwn11_auction_shortfall_loss_is_recognised_in_the_pnl(): void
    {
        $c1190 = $this->acct('1190');
        $c5300 = $this->acct('5300');
        [, $item] = $this->auctionedForShortfall();

        $before1190 = $this->glBalance($c1190);
        $before5300 = $this->glBalance($c5300);

        $this->postJson("/api/pawning-auction-items/{$item->id}/sale", ['hammer_price' => 90000])->assertStatus(200);

        $this->assertBc('10000.00', bcsub($this->glBalance($c5300), $before5300, 2), '5300 credit-loss expense +10,000');
        $this->assertBc('0.00', bcsub($this->glBalance($c1190), $before1190, 2), '1190 contra-asset untouched');
    }

    /** F-7 fixed: the auction sale now moves cash_accounts.current_balance
     *  (routed through FinancePostingService) and writes a cashbook mirror. */
    public function test_pwn11_auction_sale_moves_physical_cash(): void
    {
        [, $item] = $this->auctionedForShortfall();

        $tillCashBefore = (string) $this->till->fresh()->current_balance;
        $tillLeafBefore = $this->glBalance($this->tillLeaf->id);

        $this->postJson("/api/pawning-auction-items/{$item->id}/sale", ['hammer_price' => 90000])->assertStatus(200);

        $this->assertBc(bcadd($tillLeafBefore, '90000.00', 2), $this->glBalance($this->tillLeaf->id), 'till leaf GL +90,000');
        $this->assertBc(bcadd($tillCashBefore, '90000.00', 2), (string) $this->till->fresh()->current_balance,
            'cash_accounts.current_balance +90,000 (F-7 fixed)');
        $this->assertDatabaseHas('cashbook_entries', ['cash_account_id' => $this->till->id, 'direction' => 'In', 'amount' => '90000.00']);
    }

    // =============================================================
    // PWN-12 — auction sale, surplus: cash moves through FPS (F-7 fixed)
    // =============================================================
    public function test_pwn12_auction_sale_surplus_moves_cash(): void
    {
        $c1120 = $this->acct('1120');
        $c4025 = $this->acct('4025');
        $c2110 = $this->acct('2110');
        $type = $this->pawningType(['normal_rate_percent' => '9.000', 'special_rate_percent' => null, 'special_rate_days' => null]);
        $ticket = $this->createTicket($type, 100000.00, [
            ['net_weight_grams' => '10.000', 'gross_weight_grams' => '11.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');
        $collateralId = DB::table('facility_collateral')->where('credit_facility_id', $ticket->id)->value('id');

        $auctionId = $this->postJson('/api/pawning-auctions', [
            'branch_id' => $this->branchId, 'scheduled_date' => now()->toDateString(),
        ])->assertStatus(201)->json('id');
        $this->postJson("/api/pawning-auctions/{$auctionId}/items", ['facility_collateral_ids' => [$collateralId]])->assertStatus(201);
        $item = DB::table('pawning_auction_items')->where('pawning_auction_id', $auctionId)->first();

        $before2110 = $this->glBalance($c2110);
        $before4025 = $this->glBalance($c4025);
        $tillCashBefore = (string) $this->till->fresh()->current_balance;

        // hammer 130,000 ; owed 109,000
        // capitalRecovered = 100,000 ; interestRecovered = min(9,000, 30,000) = 9,000 ; surplus = 21,000
        $this->postJson("/api/pawning-auction-items/{$item->id}/sale", ['hammer_price' => 130000])->assertStatus(200);

        $lines = $this->linesFor('PawningAuctionItem', $item->id);
        $this->assertBc('130000.00', $lines[$this->tillLeaf->id]['debit']);
        $this->assertBc('100000.00', $lines[$c1120]['credit']);
        $this->assertBc('9000.00', $lines[$c4025]['credit']);
        $this->assertBc('21000.00', $lines[$c2110]['credit']);

        $this->assertBc('21000.00', bcsub($this->glBalance($c2110), $before2110, 2), '2110 surplus payable +21,000');
        $this->assertBc('9000.00', bcsub($this->glBalance($c4025), $before4025, 2));

        // F-7 fixed: physical cash moves with the GL.
        $this->assertBc(bcadd($tillCashBefore, '130000.00', 2), (string) $this->till->fresh()->current_balance,
            'cash_accounts.current_balance +130,000');
    }

    // =============================================================
    // PWN-13 — surplus refund: cash moves through FPS (F-7 fixed)
    // =============================================================
    public function test_pwn13_surplus_refund_moves_cash(): void
    {
        $c2110 = $this->acct('2110');
        $type = $this->pawningType(['normal_rate_percent' => '9.000', 'special_rate_percent' => null, 'special_rate_days' => null]);
        $ticket = $this->createTicket($type, 100000.00, [
            ['net_weight_grams' => '10.000', 'gross_weight_grams' => '11.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');
        $collateralId = DB::table('facility_collateral')->where('credit_facility_id', $ticket->id)->value('id');
        $auctionId = $this->postJson('/api/pawning-auctions', [
            'branch_id' => $this->branchId, 'scheduled_date' => now()->toDateString(),
        ])->assertStatus(201)->json('id');
        $this->postJson("/api/pawning-auctions/{$auctionId}/items", ['facility_collateral_ids' => [$collateralId]])->assertStatus(201);
        $item = DB::table('pawning_auction_items')->where('pawning_auction_id', $auctionId)->first();
        $this->postJson("/api/pawning-auction-items/{$item->id}/sale", ['hammer_price' => 130000])->assertStatus(200);

        $before2110 = $this->glBalance($c2110);
        $tillCashBefore = (string) $this->till->fresh()->current_balance;

        $this->postJson("/api/pawning-auction-items/{$item->id}/settle-surplus")->assertStatus(200);

        // 2110 relieved by 21,000 ; till leaf credited 21,000.
        $this->assertBc('-21000.00', bcsub($this->glBalance($c2110), $before2110, 2));

        // F-7 fixed: physical cash paid out drops the till balance with the GL.
        $this->assertBc(bcsub($tillCashBefore, '21000.00', 2), (string) $this->till->fresh()->current_balance,
            'cash_accounts.current_balance -21,000');
    }

    // =============================================================
    // PWN-14 — surplus forfeiture: Dr 2110 / Cr 4900, no cash event
    // =============================================================
    public function test_pwn14_surplus_forfeiture_dr_2110_cr_4900(): void
    {
        $c2110 = $this->acct('2110');
        $c4900 = $this->acct('4900');
        $type = $this->pawningType(['normal_rate_percent' => '9.000', 'special_rate_percent' => null, 'special_rate_days' => null]);
        $ticket = $this->createTicket($type, 100000.00, [
            ['net_weight_grams' => '10.000', 'gross_weight_grams' => '11.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');
        $collateralId = DB::table('facility_collateral')->where('credit_facility_id', $ticket->id)->value('id');
        $auctionId = $this->postJson('/api/pawning-auctions', [
            'branch_id' => $this->branchId, 'scheduled_date' => now()->toDateString(),
        ])->assertStatus(201)->json('id');
        $this->postJson("/api/pawning-auctions/{$auctionId}/items", ['facility_collateral_ids' => [$collateralId]])->assertStatus(201);
        $item = DB::table('pawning_auction_items')->where('pawning_auction_id', $auctionId)->first();
        $this->postJson("/api/pawning-auction-items/{$item->id}/sale", ['hammer_price' => 130000])->assertStatus(200);

        $before2110 = $this->glBalance($c2110);
        $before4900 = $this->glBalance($c4900);

        $this->postJson("/api/pawning-auction-items/{$item->id}/forfeit-surplus")->assertStatus(200);

        $this->assertBc('-21000.00', bcsub($this->glBalance($c2110), $before2110, 2));
        $this->assertBc('21000.00', bcsub($this->glBalance($c4900), $before4900, 2));
    }

    // =============================================================
    // PWN-15 — payment void restores the till and reopens the ticket
    // =============================================================
    public function test_pwn15_payment_void_restores_till_and_reopens_ticket(): void
    {
        $c1120 = $this->acct('1120');
        $type = $this->pawningType();
        $ticket = $this->createTicket($type, 200000.00, [
            ['net_weight_grams' => '20.000', 'gross_weight_grams' => '21.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');

        $this->postJson("/api/pawnings/{$ticket->id}/redeem", [
            'redemption_type' => 'Full', 'payment_method' => 'Cash', 'paid_at' => '2026-03-16',
        ])->assertStatus(200);
        $payment = DB::table('facility_payments')->where('credit_facility_id', $ticket->id)->latest('id')->first();

        $tillCashAfterRedeem = (string) $this->till->fresh()->current_balance;

        $this->postJson("/api/pawning-payments/{$payment->id}/void", ['void_reason' => 'mistake'])->assertStatus(200);

        // Till gives back the 206,000 it received.
        $this->assertBc(bcsub($tillCashAfterRedeem, '206000.00', 2), (string) $this->till->fresh()->current_balance);

        $ticket->refresh();
        $this->assertSame('Active', $ticket->status);
        $this->assertBc('200000.00', (string) $ticket->outstanding_balance_cache);
        $this->assertSame('Voided', DB::table('facility_payments')->where('id', $payment->id)->value('status'));

        // Original COLLECTION journal entry is Reversed, not deleted.
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'PawningPayment', 'source_id' => $payment->id, 'status' => 'Reversed']);
    }

    // =============================================================
    // PWN-18 — full redemption exactly on the cycle-end day (day 30)
    // =============================================================
    public function test_pwn18_full_redemption_on_exact_cycle_end_day(): void
    {
        $type = $this->pawningType();
        $ticket = $this->createTicket($type, 200000.00, [
            ['net_weight_grams' => '20.000', 'gross_weight_grams' => '21.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');
        $ticket->load('pawningDetail.pawningType');

        // day 30 -> > special_rate_days(10) -> normal 3% -> 6,000, no 2nd cycle
        $q = PawningInterestCalculator::quoteFull($ticket, Carbon::parse('2026-03-31'));
        $this->assertSame(30, $q['daysElapsed']);
        $this->assertBc('6000.00', (string) $q['interest']);
    }

    // =============================================================
    // PWN-19 — 3-article intake posts ONE balanced entry, not per-article
    // =============================================================
    public function test_pwn19_multi_article_intake_posts_one_entry(): void
    {
        $type = $this->pawningType();
        $ticket = $this->createTicket($type, 196000.00, [
            ['net_weight_grams' => '4.500', 'gross_weight_grams' => '5.000', 'rate_per_gram' => '15000.00'],
            ['net_weight_grams' => '6.000', 'gross_weight_grams' => '6.500', 'rate_per_gram' => '15000.00'],
            ['net_weight_grams' => '10.000', 'gross_weight_grams' => '11.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2026-03-01');

        $entryIds = DB::table('journal_entries')->where('source_type', 'Pawning')->where('source_id', $ticket->id)->pluck('id');
        $this->assertCount(1, $entryIds, 'disbursement is one journal entry regardless of article count');

        $lineCount = DB::table('journal_lines')->whereIn('journal_entry_id', $entryIds)->count();
        $this->assertSame(2, $lineCount, 'two lines: Dr 1120 / Cr till leaf');
    }

    // =============================================================
    // PWN-20 — a defaulted ticket with no auction stays Active forever (no sweep)
    // =============================================================
    public function test_pwn20_defaulted_ticket_with_no_auction_stays_active(): void
    {
        $c1120 = $this->acct('1120');
        $before1120 = $this->glBalance($c1120);

        $type = $this->pawningType();
        $ticket = $this->createTicket($type, 200000.00, [
            ['net_weight_grams' => '20.000', 'gross_weight_grams' => '21.000', 'rate_per_gram' => '15000.00'],
        ], startDate: '2025-01-01'); // long past due

        // No scheduled command touches pawning defaults.
        $ticket->refresh();
        $this->assertSame('Active', $ticket->status);
        $this->assertBc('200000.00', (string) $ticket->outstanding_balance_cache);
        // 1120 still carries the full advance; no provision, no write-down.
        $this->assertBc('200000.00', bcsub($this->glBalance($c1120), $before1120, 2));
    }
}
