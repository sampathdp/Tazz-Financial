<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'name', 'type', 'provider', 'reference_no', 'cost', 'currency',
        'start_date', 'expiry_date', 'reminder_days_before', 'status',
        'branch_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'expiry_date' => 'date',
        'cost' => 'decimal:2',
        'reminder_days_before' => 'integer',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Active items whose expiry falls inside their own reminder window —
     * DATEDIFF goes negative once past expiry, so already-expired items are
     * included too, not just upcoming ones.
     */
    public function scopeDueSoon(Builder $query): Builder
    {
        return $query->where('status', 'Active')
            ->whereRaw('DATEDIFF(expiry_date, CURDATE()) <= reminder_days_before');
    }
}
