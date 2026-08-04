<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumberSequence extends Model
{
    protected $fillable = [
        'module', 'branch_id', 'loan_type_id', 'funding_product_id', 'year', 'last_sequence',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function loanType()
    {
        return $this->belongsTo(LoanType::class);
    }

    public function fundingProduct()
    {
        return $this->belongsTo(FundingProduct::class);
    }

    public function documentNumberLogs()
    {
        return $this->hasMany(DocumentNumberLog::class);
    }
}
