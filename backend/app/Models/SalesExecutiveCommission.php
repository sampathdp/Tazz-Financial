<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesExecutiveCommission extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'credit_facility_id', 'sales_executive_id', 'loan_type_id', 'cycle_no', 'from_installment',
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
