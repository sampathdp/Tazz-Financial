<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityDiaryNote extends Model
{
    protected $fillable = ['credit_facility_id', 'note', 'follow_up_date', 'created_by'];

    protected $casts = [
        'follow_up_date' => 'date',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
