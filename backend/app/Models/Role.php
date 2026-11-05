<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_system', 'can_view_all_branches'];

    protected $casts = [
        'is_system' => 'boolean',
        'can_view_all_branches' => 'boolean',
    ];

    public function permissions()
    {
        return $this->hasMany(RolePermission::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function isAdmin(): bool
    {
        return $this->is_system && in_array($this->slug, ['admin', 'super_admin'], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_system && $this->slug === 'super_admin';
    }
}
