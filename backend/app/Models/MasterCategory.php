<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterCategory extends Model
{
    protected $fillable = [
        'type', 'name', 'code', 'description', 'status', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
