<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FdCommission extends Model
{
    use \App\Models\Concerns\BelongsToBranch;

    protected $fillable = [
        'fixed_deposit_id', 'branch_id', 'recipient_type', 'staff_id', 'recipient_name', 'recipient_phone',
        'commission_type', 'commission_value', 'commission_amount', 'notes',
        'paid_at', 'payment_amount', 'payment_method', 'cash_account_id', 'bank_account_id', 'reference_no',
        'payment_notes', 'paid_by', 'created_by',
    ];

    protected $casts = [
        'commission_value' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'payment_amount' => 'decimal:2',
        'paid_at' => 'date:Y-m-d',
    ];

    protected $appends = ['commission_status'];

    public function getCommissionStatusAttribute(): string
    {
        return $this->paid_at ? 'Paid' : 'Pending';
    }

    /** Amount earned: a percentage is taken once on the FD principal, a fixed value is the amount itself. */
    public static function amountFor(string $type, float $value, float $principal): float
    {
        return round($type === 'percentage' ? $principal * $value / 100 : $value, 2);
    }

    public function fixedDeposit()
    {
        return $this->belongsTo(FixedDeposit::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }
}
