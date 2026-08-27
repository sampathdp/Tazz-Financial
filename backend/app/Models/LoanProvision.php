<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanProvision extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'credit_facility_id', 'action', 'overdue_installments_at_action', 'slab_pct',
        'base_amount', 'amount', 'balance_after', 'posted_by', 'notes',
    ];

    protected $casts = [
        'overdue_installments_at_action' => 'decimal:2',
        'slab_pct' => 'integer',
        'base_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
