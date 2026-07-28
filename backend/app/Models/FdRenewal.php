<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FdRenewal extends Model
{
    protected $fillable = [
        'old_fixed_deposit_id', 'new_fixed_deposit_id', 'old_principal', 'additional_deposit_amount',
        'new_principal', 'additional_deposit_id', 'new_term_months', 'renewed_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'old_principal' => 'decimal:2',
        'additional_deposit_amount' => 'decimal:2',
        'new_principal' => 'decimal:2',
        'renewed_at' => 'date:Y-m-d',
    ];

    public function oldFixedDeposit()
    {
        return $this->belongsTo(FixedDeposit::class, 'old_fixed_deposit_id');
    }

    public function newFixedDeposit()
    {
        return $this->belongsTo(FixedDeposit::class, 'new_fixed_deposit_id');
    }

    public function additionalDeposit()
    {
        return $this->belongsTo(FdDeposit::class, 'additional_deposit_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
