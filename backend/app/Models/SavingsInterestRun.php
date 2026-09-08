<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavingsInterestRun extends Model
{
    protected $fillable = [
        'run_number', 'branch_id', 'period_from', 'period_to', 'status',
        'account_count', 'total_interest', 'posted_at', 'notes', 'created_by', 'posted_by',
    ];

    protected $casts = [
        'period_from' => 'date:Y-m-d',
        'period_to' => 'date:Y-m-d',
        'account_count' => 'integer',
        'total_interest' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function transactions()
    {
        return $this->hasMany(SavingsTransaction::class, 'interest_run_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
