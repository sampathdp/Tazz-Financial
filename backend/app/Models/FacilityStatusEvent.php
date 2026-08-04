<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityStatusEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['credit_facility_id', 'event_type', 'event_date', 'notes', 'recorded_by'];

    protected $casts = [
        'event_date' => 'date',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
