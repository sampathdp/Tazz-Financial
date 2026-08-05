<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentCategory extends Model
{
    protected $fillable = ['name', 'status', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
