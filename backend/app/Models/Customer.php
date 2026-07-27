<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $fillable = [
        'branch_id', 'code', 'full_name', 'title', 'surname', 'name_in_full', 'initials',
        'nic_no', 'passport_no', 'driving_license_no',
        'dob', 'gender', 'phone', 'alt_phone', 'email', 'address',
        'occupation_type', 'employer_name', 'job_title', 'employer_address', 'employer_phone', 'employment_start_date',
        'business_name', 'business_reg_no', 'business_type', 'business_address', 'business_phone',
        'monthly_income', 'photo_path', 'status', 'is_guarantor', 'notes', 'created_by',
    ];

    protected $casts = [
        'dob' => 'date',
        'employment_start_date' => 'date',
        'monthly_income' => 'decimal:2',
        'is_guarantor' => 'boolean',
    ];

    protected static function booted(): void
    {
        // full_name is derived, not entered directly — Title + Initials + Surname
        // (e.g. "Mr. J.K. Perera") is what every loan/list screen displays.
        static::saving(function (Customer $customer) {
            if ($customer->isDirty(['title', 'surname', 'initials'])) {
                $customer->full_name = trim(implode(' ', array_filter([
                    $customer->title, $customer->initials, $customer->surname,
                ])));
            }
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents()
    {
        return $this->hasMany(CustomerDocument::class);
    }
}
