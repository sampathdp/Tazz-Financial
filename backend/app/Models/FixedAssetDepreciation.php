<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FixedAssetDepreciation extends Model
{
    protected $fillable = ['fixed_asset_id', 'period', 'period_end', 'amount', 'journal_entry_id', 'posted_by'];

    protected $casts = ['period_end' => 'date:Y-m-d', 'amount' => 'decimal:2'];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
