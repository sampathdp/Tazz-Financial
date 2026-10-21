<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Customer;
use App\Models\CustomerBankDetail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Customer payout bank accounts: encrypted at rest, masked in API output, and must belong to the facility's customer. */
class CustomerBankDetailTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): Customer
    {
        $branchId = DB::table('branches')->insertGetId([
            'name' => 'BD Branch', 'code' => 'BD' . Str::random(3), 'branch_type' => 'Branch', 'status' => 'Active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Customer::create([
            'branch_id' => $branchId, 'code' => 'BD-' . Str::random(8), 'title' => 'Mr.', 'initials' => 'A.B.', 'surname' => 'Test', 'status' => 'Active',
        ]);
    }

    private function detail(Customer $c): CustomerBankDetail
    {
        $bank = Bank::create(['name' => 'Test Bank ' . Str::random(4)]);

        return $c->bankDetails()->create(['bank_id' => $bank->id, 'account_holder' => 'A B Test', 'bank_branch' => 'Main', 'account_number' => '1234567890']);
    }

    public function test_sensitive_fields_are_encrypted_at_rest_and_masked_in_output(): void
    {
        $d = $this->detail($this->customer());

        $raw = DB::table('customer_bank_details')->where('id', $d->id)->first();
        foreach (['account_holder', 'bank_branch', 'account_number'] as $col) {
            $this->assertStringNotContainsString($d->$col, $raw->$col);
        }

        $json = $d->fresh()->toArray();
        $this->assertArrayNotHasKey('account_number', $json);
        $this->assertSame('******7890', $json['account_number_masked']);
        $this->assertSame('1234567890', $d->fresh()->makeVisible('account_number')->toArray()['account_number']);
    }

    public function test_bank_detail_must_belong_to_the_customer(): void
    {
        $owner = $this->customer();
        $d = $this->detail($owner);

        $this->assertSame($d->id, CustomerBankDetail::assertBelongsTo($d->id, $owner->id));

        $this->expectException(ValidationException::class);
        CustomerBankDetail::assertBelongsTo($d->id, $this->customer()->id);
    }
}
