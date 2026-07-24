<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = ['parent_id', 'name', 'code', 'type', 'address', 'phone', 'email', 'is_head_office', 'status'];

    protected $casts = [
        'is_head_office' => 'boolean',
    ];

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
