<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FdDeposit extends Model
{
    use \App\Models\Concerns\BelongsToBranch;
    protected $fillable = [
        'deposit_number', 'branch_id', 'customer_id', 'deposit_date', 'amount',
        'payment_method', 'reference_no', 'bank_id', 'bank_account_id', 'notes', 'status', 'fixed_deposit_id', 'created_by',
    ];

    protected $casts = [
        'deposit_date' => 'date:Y-m-d',
        'amount' => 'decimal:2',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function fixedDeposit()
    {
        return $this->belongsTo(FixedDeposit::class);
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
