<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeedDraftFacilityDetail extends Model
{
    protected $table = 'speed_draft_facility_details';

    protected $primaryKey = 'credit_facility_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'credit_facility_id', 'speed_draft_type_id', 'payment_frequency', 'disbursement_method',
    ];

    public function speedDraft()
    {
        return $this->belongsTo(SpeedDraft::class, 'credit_facility_id');
    }

    public function speedDraftType()
    {
        return $this->belongsTo(SpeedDraftType::class);
    }
}
