<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FdJointHolder extends Model
{
    protected $fillable = ['fixed_deposit_id', 'customer_id', 'relationship', 'operating_instruction'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
