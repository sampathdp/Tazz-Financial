<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'code', 'branch_id', 'expense_category_id', 'vendor_id', 'amount', 'expense_date',
        'description', 'status', 'requested_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public const STATUSES = ['Pending', 'Approved', 'Rejected', 'Paid'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function voucher()
    {
        return $this->hasOne(PaymentVoucher::class);
    }
}
