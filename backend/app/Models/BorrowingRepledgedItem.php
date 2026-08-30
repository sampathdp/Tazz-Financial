<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BorrowingRepledgedItem extends Model
{
    protected $fillable = [
        'borrowing_id', 'pawning_id', 'appraised_value_snapshot',
        'financier_name', 'external_reference',
    ];

    protected $casts = [
        'appraised_value_snapshot' => 'decimal:2',
    ];

    public function borrowing()
    {
        return $this->belongsTo(Borrowing::class);
    }

    public function pawning()
    {
        return $this->belongsTo(Pawning::class, 'pawning_id');
    }
}
