<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualJournalEntryLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['manual_journal_entry_id', 'account_id', 'debit', 'credit', 'description'];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
