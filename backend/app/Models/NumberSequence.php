<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumberSequence extends Model
{
    protected $fillable = [
        'module', 'branch_id', 'loan_type_id', 'funding_product_id', 'year', 'last_sequence',
    ];
}
