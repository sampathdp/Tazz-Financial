<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PawningAuction extends Model
{
    protected $fillable = [
        'branch_id', 'auction_no', 'scheduled_date', 'notice_published_date',
        'auctioneer_name', 'venue', 'status', 'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'notice_published_date' => 'date',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(PawningAuctionItem::class);
    }
}
