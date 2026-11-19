<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAchievement extends Model
{
    public const PENDING = 'Pending';
    public const APPROVED = 'Approved';
    public const REJECTED = 'Rejected';

    protected $fillable = ['user_id', 'title', 'description', 'achieved_on', 'status', 'reviewed_by', 'reviewed_at', 'review_note'];

    protected $casts = ['achieved_on' => 'date:Y-m-d', 'reviewed_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
