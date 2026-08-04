<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToBranch, HasPublicUuid, SoftDeletes;

    protected $fillable = [
        'branch_id', 'code', 'full_name', 'title', 'surname', 'name_denoted_by_initials', 'initials',
        'nic_no', 'passport_no', 'driving_license_no',
        'dob', 'gender', 'phone', 'alt_phone', 'email', 'address',
        'occupation_type', 'monthly_income', 'photo_path', 'status', 'is_guarantor', 'notes', 'created_by',
    ];

    protected $casts = [
        'dob' => 'date',
        'monthly_income' => 'decimal:2',
        'is_guarantor' => 'boolean',
    ];

    // Generated column backing customers_nic_no_active_unique — an
    // implementation detail of the soft-delete-safe NIC constraint, not data
    // callers should ever read (see the create_customers_table migration).
    protected $hidden = ['nic_no_active'];

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

    /** Populated only when occupation_type = Employed. */
    public function employmentDetail()
    {
        return $this->hasOne(CustomerEmploymentDetail::class);
    }

    /** Populated only when occupation_type = SelfEmployed | BusinessOwner. */
    public function businessDetail()
    {
        return $this->hasOne(CustomerBusinessDetail::class);
    }
}
