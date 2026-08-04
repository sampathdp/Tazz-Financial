<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    protected $fillable = ['name', 'code', 'description', 'chart_of_account_id', 'status', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /** The chart_of_accounts expense account this category's spend posts to (e.g. 5010 Operating Expenses). */
    public function chartOfAccount()
    {
        return $this->belongsTo(ChartOfAccount::class);
    }
}
