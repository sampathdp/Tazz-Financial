<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PawningArticleType extends Model
{
    protected $fillable = ['name', 'code', 'status', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
