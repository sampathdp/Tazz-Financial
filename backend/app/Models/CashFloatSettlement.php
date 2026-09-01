<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The settlement record for one cashier-float period — see the
 * create_cash_float_settlements migration and App\Support\CashFloatService.
 */
class CashFloatSettlement extends Model
{
    protected $fillable = [
        'cash_account_id', 'home_cash_account_id', 'custodian_id',
        'issued_total', 'expected_amount', 'counted_amount', 'variance', 'shortage', 'excess', 'returned_total',
        'status', 'approval_request_id', 'notes', 'opened_by', 'settled_by', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'issued_total' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'counted_amount' => 'decimal:2',
        'variance' => 'decimal:2',
        'shortage' => 'decimal:2',
        'excess' => 'decimal:2',
        'returned_total' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function floatAccount()
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function homeAccount()
    {
        return $this->belongsTo(CashAccount::class, 'home_cash_account_id');
    }

    public function custodian()
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function approvalRequest()
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function settler()
    {
        return $this->belongsTo(User::class, 'settled_by');
    }
}
