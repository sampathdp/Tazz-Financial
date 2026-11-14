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
        'credit_facility_id', 'speed_draft_type_id', 'vehicle_no', 'payment_frequency', 'interest_method', 'document_charge_amount', 'reference_name', 'reference_id_no', 'reference_address', 'disbursement_method', 'disbursement_bank_account_id',
    ];

    public function speedDraft()
    {
        return $this->belongsTo(SpeedDraft::class, 'credit_facility_id');
    }

    /** Set only for a Bank disbursement — the account the money was paid out of. */
    public function disbursementBankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'disbursement_bank_account_id');
    }

    public function speedDraftType()
    {
        return $this->belongsTo(SpeedDraftType::class);
    }
}
