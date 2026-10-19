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
        'occupation_type', 'monthly_income', 'photo_path', 'status', 'blacklist_reason', 'blacklisted_at', 'blacklisted_by', 'is_guarantor', 'notes', 'created_by',
    ];

    protected $casts = [
        'dob' => 'date',
        'monthly_income' => 'decimal:2',
        'is_guarantor' => 'boolean',
        'blacklisted_at' => 'datetime',
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
            if ($customer->isDirty('status')) {
                if ($customer->status === 'Blacklisted') {
                    $customer->blacklisted_at = now();
                    $customer->blacklisted_by = \Illuminate\Support\Facades\Auth::id();
                } else {
                    $customer->blacklist_reason = $customer->blacklisted_at = $customer->blacklisted_by = null;
                }
            }

            if ($customer->isDirty(['title', 'surname', 'initials'])) {
                $customer->full_name = trim(implode(' ', array_filter([
                    $customer->title, $customer->initials, $customer->surname,
                ])));
            }
        });
    }

    /**
     * Refuses a new credit facility (loan, speed draft, pawning, FD-backed loan) for a
     * blacklisted customer. Looked up with the branch scope off: the bar applies in
     * every branch, including ones whose users can't otherwise see this customer.
     */
    public static function assertNotBlacklisted(?int $customerId): void
    {
        if (!$customerId) {
            return;
        }
        $c = static::withoutGlobalScopes()->select('id', 'code', 'full_name', 'status')->find($customerId);
        if ($c && $c->status === 'Blacklisted') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'customer_id' => "{$c->full_name} ({$c->code}) is blacklisted and cannot be given any facility.",
            ]);
        }
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
