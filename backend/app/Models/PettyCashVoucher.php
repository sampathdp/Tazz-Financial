<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PettyCashVoucher extends Model
{
    use Concerns\ScopedThroughParent;

    /** Visible only through a parent the caller's branch can see. */
    protected static array $branchParents = ['petty_cash_fund_id' => PettyCashFund::class];

    protected $fillable = [
        'code', 'petty_cash_fund_id', 'expense_category_id', 'vendor_id', 'staff_id', 'amount', 'description',
        'spent_at', 'paid_by', 'status', 'void_reason', 'voided_by', 'voided_at', 'journal_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'spent_at' => 'date',
        'voided_at' => 'datetime',
    ];

    public function fund()
    {
        return $this->belongsTo(PettyCashFund::class, 'petty_cash_fund_id');
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
