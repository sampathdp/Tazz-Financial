<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The lien itself - one FD pledged to one loan. See the facility_collateral_fd_details migration. */
class FacilityCollateralFdDetail extends Model
{
    protected $table = 'facility_collateral_fd_details';

    protected $primaryKey = 'facility_collateral_id';

    public $incrementing = false;

    protected $fillable = [
        'facility_collateral_id', 'credit_facility_id', 'fixed_deposit_id', 'fd_loan_policy_id',
        'fd_principal_snapshot', 'fd_rate_snapshot', 'fd_maturity_snapshot',
        'ltv_percent', 'lien_amount', 'max_loan_value',
        'lien_status', 'marked_at', 'marked_by', 'released_at', 'released_by', 'release_reason',
        'settled_amount', 'settlement_withdrawal_id',
    ];

    protected $casts = [
        'fd_principal_snapshot' => 'decimal:2',
        'fd_rate_snapshot' => 'decimal:3',
        'fd_maturity_snapshot' => 'date:Y-m-d',
        'ltv_percent' => 'decimal:2',
        'lien_amount' => 'decimal:2',
        'max_loan_value' => 'decimal:2',
        'settled_amount' => 'decimal:2',
        'marked_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function fixedDeposit()
    {
        return $this->belongsTo(FixedDeposit::class);
    }

    public function collateral()
    {
        return $this->belongsTo(FacilityCollateral::class, 'facility_collateral_id');
    }

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }
}
