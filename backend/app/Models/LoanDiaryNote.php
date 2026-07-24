<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanDiaryNote extends Model
{
    protected $fillable = ['loan_id', 'note', 'follow_up_date', 'created_by'];

    protected $casts = [
        'follow_up_date' => 'date',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
