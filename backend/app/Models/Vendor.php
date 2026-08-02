<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $fillable = ['name', 'code', 'contact_person', 'phone', 'email', 'address', 'tax_no', 'status'];

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
}
