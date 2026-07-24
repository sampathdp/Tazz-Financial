<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanGuarantor extends Model
{
    protected $fillable = ['loan_id', 'guarantor_id', 'guaranteed_amount'];

    protected $casts = [
        'guaranteed_amount' => 'decimal:2',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    /** The customer standing as guarantor for this loan. */
    public function guarantor()
    {
        return $this->belongsTo(Customer::class, 'guarantor_id');
    }
}
