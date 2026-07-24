<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanCollateral extends Model
{
    protected $table = 'loan_collateral';

    protected $fillable = ['loan_id', 'type', 'description', 'estimated_value', 'document_path'];

    protected $casts = [
        'estimated_value' => 'decimal:2',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
}
