<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerBusinessDetail extends Model
{
    public $incrementing = false;
    protected $primaryKey = 'customer_id';
    public $timestamps = false;

    protected $fillable = [
        'customer_id', 'business_name', 'business_reg_no', 'business_type', 'business_address', 'business_phone',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
