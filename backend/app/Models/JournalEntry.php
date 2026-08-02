<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $fillable = [
        'code', 'entry_date', 'reference_type', 'reference_id', 'description',
        'branch_id', 'posted_by', 'posted_at', 'status', 'reversal_of_id',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
    ];

    public const STATUSES = ['Posted', 'Reversed'];

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversalOf()
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_id');
    }
}
