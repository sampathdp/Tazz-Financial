<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * One row in the finance audit trail — see the create_finance_audit_events
 * migration. Write only via record(); never updated.
 */
class FinanceAuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id', 'action', 'subject_type', 'subject_id', 'from_status', 'to_status',
        'amount', 'branch_id', 'reason', 'ip', 'user_agent', 'meta', 'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Record an audit event. actor / ip / user_agent default from the current
     * request when not given. Never throws — an audit failure must not roll
     * back the business action it is recording.
     *
     * @param array{
     *   actor_id?:?int, from_status?:?string, to_status?:?string, amount?:mixed,
     *   branch_id?:?int, reason?:?string, meta?:?array
     * } $extra
     */
    public static function record(string $action, ?Model $subject = null, array $extra = []): void
    {
        try {
            $req = request();
            static::create([
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'actor_id' => $extra['actor_id'] ?? Auth::id(),
                'from_status' => $extra['from_status'] ?? null,
                'to_status' => $extra['to_status'] ?? null,
                'amount' => $extra['amount'] ?? null,
                'branch_id' => $extra['branch_id'] ?? null,
                'reason' => $extra['reason'] ?? null,
                'ip' => $req?->ip(),
                'user_agent' => substr((string) $req?->userAgent(), 0, 255) ?: null,
                'meta' => $extra['meta'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('errors')->warning('Finance audit event not recorded', [
                'action' => $action, 'error' => $e->getMessage(),
            ]);
        }
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
