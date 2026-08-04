<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashReconciliation extends Model
{
    protected $fillable = [
        'branch_id', 'as_at_date',
        'system_cash_total', 'cheque_in_hand_total', 'postdated_cheque_total',
        'today_cash_receipts', 'today_cheque_receipts', 'cheque_realized_total',
        'receipts_to_be_cancelled', 'encashment', 'cash_float', 'petty_cash',
        'other_branch_cash', 'other_branch_id', 'other_branch_cheque', 'other_branch_cheque_branch_id',
        'total_cash', 'total_cheque',
        'denominations', 'coins_amount', 'counted_total', 'variance',
        'notes', 'reconciled_by',
    ];

    protected $casts = [
        'as_at_date' => 'date',
        'system_cash_total' => 'decimal:2',
        'cheque_in_hand_total' => 'decimal:2',
        'postdated_cheque_total' => 'decimal:2',
        'today_cash_receipts' => 'decimal:2',
        'today_cheque_receipts' => 'decimal:2',
        'cheque_realized_total' => 'decimal:2',
        'receipts_to_be_cancelled' => 'decimal:2',
        'encashment' => 'decimal:2',
        'cash_float' => 'decimal:2',
        'petty_cash' => 'decimal:2',
        'other_branch_cash' => 'decimal:2',
        'other_branch_cheque' => 'decimal:2',
        'total_cash' => 'decimal:2',
        'total_cheque' => 'decimal:2',
        'coins_amount' => 'decimal:2',
        'counted_total' => 'decimal:2',
        'variance' => 'decimal:2',
        'denominations' => 'array',
    ];

    /** Note values counted on the physical cash count — keys used in the `denominations` JSON column. */
    public const DENOMINATIONS = [5000, 2000, 1000, 500, 200, 100, 50, 20, 10];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function otherBranch()
    {
        return $this->belongsTo(Branch::class, 'other_branch_id');
    }

    /** Named otherBranchChequeBranch (not otherBranchCheque) — that name would
     *  serialize to the same JSON key as the other_branch_cheque amount
     *  column above, silently clobbering it whenever both are present. */
    public function otherBranchChequeBranch()
    {
        return $this->belongsTo(Branch::class, 'other_branch_cheque_branch_id');
    }

    public function reconciler()
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }
}
