<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerEmploymentDetail extends Model
{
    public $incrementing = false;
    protected $primaryKey = 'customer_id';
    public $timestamps = false;

    protected $fillable = [
        'customer_id', 'employer_name', 'job_title', 'employer_address', 'employer_phone', 'employment_start_date',
    ];

    protected $casts = [
        'employment_start_date' => 'date',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
