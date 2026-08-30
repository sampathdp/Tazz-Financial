<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacilityDocument extends Model
{
    protected $fillable = ['credit_facility_id', 'document_category_id', 'file_path', 'uploaded_by'];

    // Some hosts' PDO returns integer columns as strings — cast the FKs so the
    // ownership guards in LoanDocumentController (strict !==) never misfire.
    protected $casts = [
        'credit_facility_id' => 'integer',
        'document_category_id' => 'integer',
        'uploaded_by' => 'integer',
    ];

    protected $with = ['category'];

    public function facility()
    {
        return $this->belongsTo(Loan::class, 'credit_facility_id');
    }

    public function category()
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
