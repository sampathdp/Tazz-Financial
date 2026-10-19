<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\Pawning;
use App\Models\SpeedDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** A blacklisted customer cannot be given a new loan, speed draft or pawning, in any branch. */
class CustomerBlacklistTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(string $status): Customer
    {
        $branchId = DB::table('branches')->insertGetId([
            'name' => 'BL Branch', 'code' => 'BL' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Customer::create([
            'branch_id' => $branchId, 'code' => 'BL-' . Str::random(8), 'title' => 'Mr.', 'initials' => 'A.B.', 'surname' => 'Test',
            'status' => $status, 'blacklist_reason' => $status === 'Blacklisted' ? 'Defaulted' : null,
        ]);
    }

    public function test_blacklisting_stamps_when_and_clearing_wipes_it(): void
    {
        $c = $this->customer('Blacklisted');
        $this->assertNotNull($c->fresh()->blacklisted_at);

        $c->update(['status' => 'Active']);
        $c = $c->fresh();
        $this->assertNull($c->blacklisted_at);
        $this->assertNull($c->blacklist_reason);
    }

    public function test_facility_models_refuse_blacklisted_customer(): void
    {
        $c = $this->customer('Blacklisted');

        foreach ([Loan::class, SpeedDraft::class, Pawning::class] as $model) {
            try {
                $model::create(['branch_id' => $c->branch_id, 'customer_id' => $c->id, 'code' => 'X-' . Str::random(6)]);
                $this->fail("$model accepted a blacklisted customer");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('customer_id', $e->errors());
            }
        }
    }

    public function test_active_customer_passes_guard(): void
    {
        Customer::assertNotBlacklisted($this->customer('Active')->id);
        $this->assertTrue(true);
    }
}
