<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ChartOfAccount;
use App\Models\FdPartPayment;
use App\Models\FixedDeposit;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REAL concurrency: each case starts two separate PHP processes (separate database connections and
 * transactions) that fire the same kind of request at the same instant, with DIFFERENT idempotency keys
 * (two employees, so the request-level replay guard cannot help). Exactly one may succeed and the money
 * must move once.
 *
 * These tests COMMIT data (a worker cannot see another connection's open transaction), so they are
 * excluded from the normal run and must only be run against a disposable database:
 *     DB_DATABASE=<scratch>_test php vendor/bin/phpunit --group concurrency
 *
 * @group concurrency
 */
class ConcurrencyTest extends TestCase
{
    private User $admin;

    private int $branchId;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        if (! ChartOfAccount::where('code', '2010')->exists()) {
            $this->markTestSkipped('Chart of accounts not seeded.');
        }
        $this->admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('No admin user seeded.');
        $this->branchId = DB::table('branches')->insertGetId([
            'name' => 'CC Branch', 'code' => 'C' . Str::random(2), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customerId = DB::table('customers')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'code' => 'C' . Str::random(7),
            'full_name' => 'CC Customer', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Runs the given requests at the same instant in separate processes; returns [status, decoded body] for each. */
    private function race(array $requests): array
    {
        $startAt = microtime(true) + 4.0; // every worker finishes booting first, then they all fire together
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'FINANCE_STRICT_TILL_PURPOSES' => 'false', 'FINANCE_DISBURSE_FROM_HO_BANK' => 'false',
            'LOG_CHANNEL' => getenv('CC_LOG') ?: 'null', 'CACHE_DRIVER' => 'array',
        ]);
        $procs = [];
        foreach ($requests as $i => $r) {
            $spec = base64_encode(json_encode($r + ['user_id' => $this->admin->id, 'start_at' => $startAt, 'key' => 'cc-' . Str::uuid()]));
            // Output goes to files, not pipes: a worker that logs a long stack trace would otherwise fill the pipe and hang.
            $outFile = tempnam(sys_get_temp_dir(), 'ccw');
            $cmd = [PHP_BINARY, base_path('tests/Support/concurrency_worker.php'), $spec];
            $proc = proc_open($cmd, [1 => ['file', $outFile, 'w'], 2 => ['file', $outFile, 'a']], $pipes, base_path(), $env);
            $procs[$i] = [$proc, $outFile];
        }
        $out = [];
        foreach ($procs as $i => [$proc, $outFile]) {
            proc_close($proc);
            $raw = (string) file_get_contents($outFile);
            @unlink($outFile);
            $decoded = json_decode(substr($raw, (int) strrpos($raw, '{"status"')), true);
            $this->assertNotNull($decoded, "worker {$i} produced no result: " . substr($raw, -2000));
            $out[$i] = [$decoded['status'], json_decode($decoded['body'], true)];
        }

        return $out;
    }

    private function statuses(array $results): array
    {
        $s = array_column($results, 0);
        sort($s);

        return $s;
    }

    public function test_two_part_payments_on_one_fd_apply_once(): void
    {
        $till = CashAccount::create(['code' => 'CC-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain', 'name' => 'CC Till', 'opening_balance' => 0, 'current_balance' => '10000000.00', 'status' => 'Active']);
        ChartOfAccount::forCashTill($till->fresh());
        $productId = DB::table('funding_products')->insertGetId([
            'name' => 'CC Product ' . Str::random(4), 'code' => 'CP' . Str::random(5), 'interest_method' => 'maturity', 'annual_rate_percent' => '8.000',
            'allowed_terms_months' => json_encode([12]), 'min_deposit_amount' => '1000.00', 'status' => 'Active',
            'premature_withdrawal_rate_percent' => '4.000', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fdId = DB::table('fixed_deposits')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'fd_number' => 'FD' . Str::random(8), 'branch_id' => $this->branchId,
            'customer_id' => $this->customerId, 'funding_product_id' => $productId,
            'principal_amount' => '100000.00', 'interest_rate_percent' => '8.000', 'interest_payment_method' => 'maturity',
            'term_months' => 12, 'start_date' => now()->subDays(100)->toDateString(), 'maturity_date' => now()->addDays(265)->toDateString(),
            'total_interest_amount' => '8000.00', 'maturity_amount' => '108000.00', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $body = ['amount' => 60000, 'payment_method' => 'Cash'];
        $res = $this->race([
            ['method' => 'POST', 'uri' => "/api/fixed-deposits/{$fdId}/part-payment", 'body' => $body],
            ['method' => 'POST', 'uri' => "/api/fixed-deposits/{$fdId}/part-payment", 'body' => $body],
        ]);

        $this->assertSame([201, 422], $this->statuses($res), json_encode($res));
        $this->assertSame(1, FdPartPayment::where('fixed_deposit_id', $fdId)->count());
        $this->assertEqualsWithDelta(40000.0, (float) FixedDeposit::find($fdId)->principal_amount, 0.001);
    }

    public function test_two_withdrawals_cannot_overdraw_a_savings_account(): void
    {
        $till = CashAccount::create(['code' => 'CC-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain', 'name' => 'CC Till', 'opening_balance' => 0, 'current_balance' => '1000000.00', 'status' => 'Active']);
        ChartOfAccount::forCashTill($till->fresh());
        $product = SavingsProduct::create([
            'name' => 'CC Daily', 'code' => 'CD' . Str::random(4), 'interest_rate_percent' => '12.000',
            'interest_calc_method' => 'min_monthly_balance', 'interest_posting_frequency' => 'monthly',
            'min_opening_deposit' => '0', 'min_balance' => '0', 'withdrawal_fee' => '0', 'ledger_fee' => '0', 'dormancy_days' => 0, 'status' => 'Active',
        ]);
        $account = SavingsAccount::create([
            'account_number' => 'CCS-' . Str::random(5), 'branch_id' => $this->branchId, 'customer_id' => $this->customerId,
            'savings_product_id' => $product->id, 'interest_rate_percent' => '12.000',
            'interest_calc_method' => 'min_monthly_balance', 'interest_posting_frequency' => 'monthly',
            'min_balance' => '0', 'withdrawal_fee' => '0', 'open_date' => now()->startOfMonth()->toDateString(),
            'status' => 'Active', 'created_by' => $this->admin->id,
        ]);
        DB::table('savings_accounts')->where('id', $account->id)->update(['current_balance' => '1000.00']);

        $body = ['amount' => 700, 'txn_date' => now()->toDateString(), 'payment_method' => 'Cash'];
        $res = $this->race([
            ['method' => 'POST', 'uri' => "/api/savings-accounts/{$account->id}/withdraw", 'body' => $body],
            ['method' => 'POST', 'uri' => "/api/savings-accounts/{$account->id}/withdraw", 'body' => $body],
        ]);

        $this->assertSame([201, 422], $this->statuses($res), json_encode($res));
        $this->assertSame('300.00', (string) DB::table('savings_accounts')->where('id', $account->id)->value('current_balance'));
    }

    public function test_two_clears_of_one_bank_transaction_move_the_bank_once(): void
    {
        $bank = BankAccount::create(['branch_id' => $this->branchId, 'bank_id' => DB::table('banks')->value('id'), 'name' => 'CC Current', 'account_number' => Str::random(10), 'account_type' => 'Current', 'currency' => 'LKR', 'opening_balance' => '100000.00', 'current_balance' => '100000.00', 'status' => 'Active']);
        $expense = ChartOfAccount::where('account_type', 'Expense')->value('id');
        $created = $this->actingAs($this->admin, 'sanctum')->postJson('/api/bank-transactions', [
            'bank_account_id' => $bank->id, 'txn_date' => now()->toDateString(), 'transaction_type' => 'Withdrawal', 'amount' => '250.00',
            'status' => 'Pending', 'contra_kind' => 'Ledger', 'contra_chart_of_account_id' => $expense,
        ])->assertStatus(201)->json();
        $this->assertSame('100000.00', (string) $bank->fresh()->current_balance, 'a pending transaction must not move the bank yet');

        $res = $this->race([
            ['method' => 'POST', 'uri' => "/api/bank-transactions/{$created['id']}/clear", 'body' => []],
            ['method' => 'POST', 'uri' => "/api/bank-transactions/{$created['id']}/clear", 'body' => []],
        ]);

        $this->assertSame(200, $this->statuses($res)[0], json_encode($res));
        $this->assertSame('99750.00', (string) $bank->fresh()->current_balance, 'the bank must move exactly once: ' . json_encode($res));
        $this->assertSame(1, DB::table('finance_transactions')->where('source_module', 'BankTransaction')->where('source_id', $created['id'])->count());
    }

    /** A matured FD of 100,000 with a till for its branch; returns the FD id. */
    private function maturedFd(): int
    {
        $till = CashAccount::create(['code' => 'CC-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain', 'name' => 'CC Till', 'opening_balance' => 0, 'current_balance' => '10000000.00', 'status' => 'Active']);
        ChartOfAccount::forCashTill($till->fresh());
        $productId = DB::table('funding_products')->insertGetId([
            'name' => 'CC Product ' . Str::random(4), 'code' => 'P' . Str::random(2), 'interest_method' => 'maturity', 'annual_rate_percent' => '8.000',
            'allowed_terms_months' => json_encode([12]), 'min_deposit_amount' => '1000.00', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('fixed_deposits')->insertGetId([
            'public_uuid' => (string) Str::uuid(), 'fd_number' => 'FD' . Str::random(8), 'branch_id' => $this->branchId,
            'customer_id' => $this->customerId, 'funding_product_id' => $productId,
            'principal_amount' => '100000.00', 'interest_rate_percent' => '8.000', 'interest_payment_method' => 'maturity',
            'term_months' => 12, 'start_date' => now()->subMonths(12)->subDays(2)->toDateString(), 'maturity_date' => now()->subDay()->toDateString(),
            'total_interest_amount' => '8000.00', 'maturity_amount' => '108000.00', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_two_renewals_of_one_matured_fd_create_one_new_fd(): void
    {
        $fdId = $this->maturedFd();
        $body = ['term_months' => 12, 'interest_treatment' => 'payout'];
        $res = $this->race([
            ['method' => 'POST', 'uri' => "/api/fixed-deposits/{$fdId}/renew", 'body' => $body],
            ['method' => 'POST', 'uri' => "/api/fixed-deposits/{$fdId}/renew", 'body' => $body],
        ]);

        $this->assertSame([201, 422], $this->statuses($res), json_encode($res));
        $this->assertSame(1, DB::table('fd_renewals')->where('old_fixed_deposit_id', $fdId)->count());
        $this->assertSame(1, DB::table('fixed_deposits')->where('renewed_from_id', $fdId)->count());
    }

    public function test_two_withdrawals_of_one_matured_fd_pay_out_once(): void
    {
        $fdId = $this->maturedFd();
        $body = ['payment_method' => 'Cash'];
        $res = $this->race([
            ['method' => 'POST', 'uri' => "/api/fixed-deposits/{$fdId}/withdraw", 'body' => $body],
            ['method' => 'POST', 'uri' => "/api/fixed-deposits/{$fdId}/withdraw", 'body' => $body],
        ]);

        $this->assertSame([201, 422], $this->statuses($res), json_encode($res));
        $this->assertSame(1, DB::table('fd_withdrawals')->where('fixed_deposit_id', $fdId)->count());
    }

    public function test_two_petty_cash_vouchers_cannot_overspend_a_float(): void
    {
        $till = CashAccount::create(['code' => 'CC-' . Str::random(6), 'branch_id' => $this->branchId, 'account_type' => 'BranchMain', 'name' => 'CC Till', 'opening_balance' => 0, 'current_balance' => '1000000.00', 'status' => 'Active']);
        ChartOfAccount::forCashTill($till->fresh());
        $api = $this->actingAs($this->admin, 'sanctum');
        $fund = $api->postJson('/api/petty-cash-funds', ['branch_id' => $this->branchId, 'name' => 'CC Float', 'float_limit' => 10000, 'custodian_id' => $this->admin->id])->assertStatus(201)->json();
        $api->postJson('/api/petty-cash-replenishments', ['petty_cash_fund_id' => $fund['id'], 'amount' => 1000, 'funding_method' => 'Cash', 'cash_account_id' => $till->id])->assertStatus(201);
        $category = DB::table('expense_categories')->whereNotNull('chart_of_account_id')->value('id') ?? $this->markTestSkipped('No expense category with a ledger account.');

        $body = ['petty_cash_fund_id' => $fund['id'], 'expense_category_id' => $category, 'amount' => 700, 'description' => 'race'];
        $res = $this->race([
            ['method' => 'POST', 'uri' => '/api/petty-cash-vouchers', 'body' => $body],
            ['method' => 'POST', 'uri' => '/api/petty-cash-vouchers', 'body' => $body],
        ]);

        $this->assertSame([201, 422], $this->statuses($res), json_encode($res));
        $this->assertSame('300.00', (string) DB::table('petty_cash_funds')->where('id', $fund['id'])->value('current_balance'));
    }
}
