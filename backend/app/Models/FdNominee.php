<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FdNominee extends Model
{
    protected $fillable = [
        'fixed_deposit_id', 'full_name', 'relationship', 'nic_no', 'date_of_birth', 'address', 'phone',
        'share_percent', 'guardian_name', 'guardian_nic_no',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'share_percent' => 'decimal:2',
    ];
}
