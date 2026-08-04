<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Renamed from GeneratedNumber. branch_id/loan_type_id/funding_product_id/
 * year are gone — they described which number_sequences counter this number
 * was drawn from, and now live on that row once instead of being copied onto
 * every number ever issued from it (reach them via numberSequence). See the
 * document_number_log migration for why source_type/source_id start null.
 */
class DocumentNumberLog extends Model
{
    protected $table = 'document_number_log';

    const UPDATED_AT = null;
    const CREATED_AT = 'generated_at';

    protected $fillable = [
        'number_sequence_id', 'generated_number', 'source_type', 'source_id', 'generated_by',
    ];

    public function numberSequence()
    {
        return $this->belongsTo(NumberSequence::class);
    }

    public function generator()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
