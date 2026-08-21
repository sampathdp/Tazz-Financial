<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PawningGoldRate extends Model
{
    protected $fillable = ['effective_date', 'karat', 'rate_per_gram', 'branch_id', 'created_by'];

    protected $casts = [
        'effective_date' => 'date',
        'karat' => 'decimal:2',
        'rate_per_gram' => 'decimal:2',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
