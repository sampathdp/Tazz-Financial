<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A manually-authored journal entry in staging — see the table migration.
 * Becomes a real journal_entries row (via LedgerPoster) only once its
 * approval_requests row (code 'manual_journal_entry') clears.
 */
class ManualJournalEntry extends Model
{
    protected $fillable = [
        'code', 'entry_date', 'description', 'branch_id', 'status',
        'requested_by', 'reason', 'journal_entry_id', 'reject_reason',
    ];

    protected $casts = [
        'entry_date' => 'date:Y-m-d',
    ];

    public function lines()
    {
        return $this->hasMany(ManualJournalEntryLine::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** The still-open approval_requests row for this entry, if any. */
    public function pendingApprovalRequest()
    {
        return $this->hasOne(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'manual_journal_entry'))
            ->where('status', 'PendingApproval');
    }
}
