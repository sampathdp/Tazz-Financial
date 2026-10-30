<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAchievement extends Model
{
    protected $fillable = ['user_id', 'title', 'description', 'achieved_on'];

    protected $casts = ['achieved_on' => 'date:Y-m-d'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
