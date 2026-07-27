<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratedNumber extends Model
{
    protected $fillable = [
        'module', 'number', 'reference_type', 'reference_id',
        'branch_id', 'loan_type_id', 'year', 'sequence', 'generated_by',
    ];

    public function reference()
    {
        return $this->morphTo();
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function loanType()
    {
        return $this->belongsTo(LoanType::class);
    }

    public function generator()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
