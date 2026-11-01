<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityDiaryNote extends Model
{
    use Concerns\ScopedThroughParent;

    /** Visible only through a parent the caller's branch can see. */
    protected static array $branchParents = ['credit_facility_id' => Loan::class];

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
