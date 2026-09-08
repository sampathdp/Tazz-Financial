<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavingsCollectionSheet extends Model
{
    protected $fillable = [
        'sheet_number', 'branch_id', 'collector_id', 'collection_date', 'status',
        'total_amount', 'line_count', 'posted_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'collection_date' => 'date:Y-m-d',
        'total_amount' => 'decimal:2',
        'line_count' => 'integer',
        'posted_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function collector()
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function lines()
    {
        return $this->hasMany(SavingsCollectionLine::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
