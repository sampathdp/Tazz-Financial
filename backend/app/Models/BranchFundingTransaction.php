<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchFundingTransaction extends Model
{
    protected $fillable = ['agreement_id', 'kind', 'txn_date', 'principal_amount', 'interest_amount', 'journal_entry_id', 'created_by'];

    protected $casts = [
        'txn_date' => 'date:Y-m-d',
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
    ];
}
