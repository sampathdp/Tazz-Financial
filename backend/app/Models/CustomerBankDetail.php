<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

/**
 * A customer's payout bank account. Holder, branch and account number are
 * encrypted at rest. The full account number is hidden from serialisation by
 * default - lists and pickers get account_number_masked; the payout lists that
 * actually need the number call makeVisible('account_number') explicitly.
 */
class CustomerBankDetail extends Model
{
    use SoftDeletes;

    protected $fillable = ['customer_id', 'bank_id', 'account_holder', 'bank_branch', 'account_number', 'created_by'];

    protected $casts = [
        'account_holder' => 'encrypted',
        'bank_branch' => 'encrypted',
        'account_number' => 'encrypted',
    ];

    protected $hidden = ['account_number'];

    protected $appends = ['account_number_masked'];

    public function getAccountNumberMaskedAttribute(): string
    {
        $n = (string) $this->account_number;

        return str_repeat('*', max(strlen($n) - 4, 0)) . substr($n, -4);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    /** Rejects a missing id, or one that belongs to a different customer; returns the validated id. */
    public static function assertBelongsTo(?int $id, int $customerId, string $field = 'customer_bank_detail_id'): int
    {
        if (!$id || !static::where('id', $id)->where('customer_id', $customerId)->exists()) {
            throw ValidationException::withMessages([$field => 'Select one of this customer\'s bank accounts for payments.']);
        }

        return $id;
    }
}
