<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An amount band for an approval_types code (optionally scoped to one branch)
 * mapping [min_amount, max_amount] to how many distinct approvals are
 * required. See the create_approval_tiers migration and App\Support\ApprovalRouter.
 */
class ApprovalTier extends Model
{
    protected $fillable = [
        'approval_type_id', 'branch_id', 'min_amount', 'max_amount', 'required_approvals', 'notes',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'required_approvals' => 'integer',
    ];

    public function approvalType()
    {
        return $this->belongsTo(ApprovalType::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
