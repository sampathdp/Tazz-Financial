<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class SavingsAccount extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'public_uuid', 'account_number', 'branch_id', 'customer_id', 'savings_product_id',
        'interest_rate_percent', 'interest_calc_method', 'interest_posting_frequency', 'min_balance',
        'withdrawal_fee', 'open_date', 'current_balance', 'interest_accrued', 'last_interest_posted_on',
        'last_activity_on', 'status', 'closed_on', 'notes', 'created_by',
    ];

    protected $casts = [
        'interest_rate_percent' => 'decimal:3',
        'min_balance' => 'decimal:2',
        'withdrawal_fee' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'interest_accrued' => 'decimal:2',
        'open_date' => 'date:Y-m-d',
        'last_interest_posted_on' => 'date:Y-m-d',
        'last_activity_on' => 'date:Y-m-d',
        'closed_on' => 'date:Y-m-d',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function savingsProduct()
    {
        return $this->belongsTo(SavingsProduct::class);
    }

    public function transactions()
    {
        return $this->hasMany(SavingsTransaction::class)->orderByDesc('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Balance the customer may still draw without breaching the product minimum. */
    public function getAvailableBalanceAttribute(): string
    {
        return bcsub((string) $this->current_balance, (string) $this->min_balance, 2);
    }
}
