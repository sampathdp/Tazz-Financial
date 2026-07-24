<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerDocument extends Model
{
    protected $fillable = ['customer_id', 'doc_type', 'file_path', 'uploaded_by'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
