<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalType extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_system', 'required_approvals'];

    protected $casts = [
        'is_system' => 'boolean',
        'required_approvals' => 'integer',
    ];

    public function approvers()
    {
        return $this->belongsToMany(User::class, 'approval_type_approvers');
    }

    public function requests()
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    /** Admins can always approve; everyone else must be a hand-picked approver
     *  for this specific code. Unlike the old per-branch pool, an unconfigured
     *  (approver-less) type is admin-only, not wide open — this is the sole
     *  gate for these actions, not an extra restriction on top of a broader
     *  permission. */
    public static function isAuthorized(User $user, string $code): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return static::where('code', $code)->whereHas('approvers', fn ($q) => $q->where('users.id', $user->id))->exists();
    }

    /** Every code $user may approve — admins get all of them (see AuthController::present()). */
    public static function codesForUser(User $user): array
    {
        if ($user->isAdmin()) {
            return static::pluck('code')->all();
        }

        return static::whereHas('approvers', fn ($q) => $q->where('users.id', $user->id))->pluck('code')->all();
    }
}
