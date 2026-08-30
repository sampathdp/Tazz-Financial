<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerDocument extends Model
{
    protected $fillable = ['customer_id', 'doc_type', 'file_path', 'uploaded_by'];

    // Some hosts' PDO returns integer columns as strings — cast the FKs so the
    // ownership guards in CustomerDocumentController (strict !==) never misfire.
    protected $casts = [
        'customer_id' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
