<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Renamed from UserPermission — a per-user exception layered on top of the
 * user's role grid (see App\Support\Permissions::gridForUser), not a
 * standalone grant. `allowed` = true forces a grant even if the role denies
 * it, false forces a denial even if the role grants it; the absence of a row
 * means "inherit from the role."
 */
class UserPermissionOverride extends Model
{
    protected $fillable = ['user_id', 'permission_id', 'allowed'];

    protected $casts = [
        'allowed' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }
}
