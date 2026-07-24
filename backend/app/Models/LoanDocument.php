<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanDocument extends Model
{
    protected $fillable = ['loan_id', 'doc_type', 'file_path', 'uploaded_by'];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
