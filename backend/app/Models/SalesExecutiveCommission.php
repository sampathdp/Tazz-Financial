<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesExecutiveCommission extends Model
{
    /** The branch comes from the parent record (see the add_branch_id_to_money_child_tables migration). */
    protected static function booted(): void
    {
        static::creating(function ($m) {
            if (empty($m->staff_id) && $m->sales_executive_id) {
                $m->staff_id = Staff::where('user_id', $m->sales_executive_id)->value('id');
            }
            if (empty($m->branch_id) && $m->credit_facility_id) {
                $m->branch_id = \App\Models\Loan::withoutGlobalScopes()->whereKey($m->credit_facility_id)->value('branch_id');
            }
        });
    }

    public $timestamps = false;

    protected $fillable = [
        'credit_facility_id', 'sales_executive_id', 'staff_id', 'loan_type_id', 'cycle_no', 'from_installment',
        'commission_type', 'commission_value', 'commission_amount', 'required_installments',
        'paid_at', 'payment_amount', 'payment_method', 'cash_account_id', 'bank_account_id', 'reference_no', 'notes', 'paid_by',
    ];

    protected $casts = [
        'cycle_no' => 'integer',
        'from_installment' => 'integer',
        'commission_value' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'required_installments' => 'integer',
        'paid_at' => 'datetime',
        'payment_amount' => 'decimal:2',
    ];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class);
    }

    public function salesExecutive()
    {
        return $this->belongsTo(User::class, 'sales_executive_id');
    }

    public function loanType()
    {
        return $this->belongsTo(LoanType::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }
}
