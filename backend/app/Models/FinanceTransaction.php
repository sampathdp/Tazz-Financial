<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One actual movement of physical cash or bank money — see the
 * create_finance_transactions_table migration. Written only by
 * App\Support\FinancePostingService. Immutable once Completed.
 */
class FinanceTransaction extends Model
{
    public const TXN_TYPES = [
        'CASH_IN', 'CASH_OUT', 'TRANSFER', 'DISBURSEMENT', 'EXPENSE_PAYMENT',
        'FLOAT_ISSUE', 'FLOAT_RETURN', 'COLLECTION', 'BANK_DEPOSIT',
        'BANK_WITHDRAWAL', 'ADJUSTMENT', 'REVERSAL',
    ];

    protected $fillable = [
        'code', 'txn_type', 'direction',
        'source_account_type', 'source_account_id', 'dest_account_type', 'dest_account_id',
        'amount', 'currency', 'branch_id', 'finance_request_id', 'source_module', 'source_id',
        'journal_entry_id', 'status', 'reversal_of_id', 'idempotency_key',
        'description', 'meta',
        'requested_by', 'approved_by', 'executed_by', 'received_by', 'executed_at', 'received_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta' => 'array',
        'executed_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function executor()
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** The cash/bank account model on the source side, or null for a pure inbound. */
    public function sourceAccount()
    {
        return $this->resolveAccount($this->source_account_type, $this->source_account_id);
    }

    /** The cash/bank account model on the destination side, or null for a pure outbound. */
    public function destAccount()
    {
        return $this->resolveAccount($this->dest_account_type, $this->dest_account_id);
    }

    private function resolveAccount(?string $type, ?int $id): CashAccount|BankAccount|null
    {
        if (!$type || !$id) {
            return null;
        }

        return $type === 'cash' ? CashAccount::find($id) : BankAccount::find($id);
    }
}
