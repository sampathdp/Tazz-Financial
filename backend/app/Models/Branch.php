<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = ['parent_id', 'name', 'code', 'branch_type', 'address', 'phone', 'email', 'is_head_office', 'status', 'funding_borrow_limit'];

    protected $casts = [
        'is_head_office' => 'boolean',
    ];

    protected static function booted(): void
    {
        // However a branch is created, it gets a till for every purpose (BranchProvisioner is idempotent),
        // so no module can find the branch without the till it posts to.
        static::created(fn (self $branch) => \App\Support\BranchProvisioner::provision($branch));
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /** The owning Branch, when this row is a Center. */
    public function parent()
    {
        return $this->belongsTo(Branch::class, 'parent_id');
    }

    /** Centers that report up to this Branch. */
    public function centers()
    {
        return $this->hasMany(Branch::class, 'parent_id');
    }
}
