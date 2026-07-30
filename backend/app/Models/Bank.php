<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bank extends Model
{
    protected $fillable = ['name', 'code', 'description', 'status', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
