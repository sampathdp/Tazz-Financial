<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityDocument extends Model
{
    protected $fillable = ['credit_facility_id', 'doc_type', 'file_path', 'uploaded_by'];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
