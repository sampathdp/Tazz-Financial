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

    // photo_path is an internal storage key — callers fetch the image from the
    // customers/{id}/photo route (auth-protected) and only need to know whether
    // one exists, which has_photo answers.
    protected $appends = ['has_photo'];

    // nic_no_active: generated column backing customers_nic_no_active_unique, an
    // implementation detail of the soft-delete-safe NIC constraint (see the
    // create_customers_table migration). photo_path: see $appends above.
    protected $hidden = ['nic_no_active', 'photo_path'];

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

    public function getHasPhotoAttribute(): bool
    {
        return !empty($this->photo_path);
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
