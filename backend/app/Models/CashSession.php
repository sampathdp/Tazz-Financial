<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/**
 * A day's open/close for a cash account — see the
 * create_finance_settings_and_cash_sessions migration and
 * App\Support\CashSessionService.
 */
class CashSession extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'cash_account_id', 'branch_id', 'business_date',
        'opening_amount', 'opening_expected', 'expected_closing', 'counted_closing',
        'variance', 'shortage', 'excess', 'denominations', 'coins_amount',
        'status', 'notes', 'opened_by', 'closed_by', 'verified_by', 'opened_at', 'closed_at', 'verified_at',
    ];

    protected $casts = [
        'business_date' => 'date',
        'opening_amount' => 'decimal:2',
        'opening_expected' => 'decimal:2',
        'expected_closing' => 'decimal:2',
        'counted_closing' => 'decimal:2',
        'variance' => 'decimal:2',
        'shortage' => 'decimal:2',
        'excess' => 'decimal:2',
        'coins_amount' => 'decimal:2',
        'denominations' => 'array',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
