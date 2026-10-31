<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    use \App\Models\Concerns\BelongsToBranch;

    /** HR/head-office staff with no branch stay visible to every branch (they can be commission recipients anywhere). */
    public static $sharedWhenNoBranch = true;

    protected $table = 'staff';

    protected $fillable = ['name', 'nic_no', 'phone', 'email', 'designation', 'branch_id', 'user_id', 'status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function commissions()
    {
        return $this->hasMany(SalesExecutiveCommission::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    /** Matches code, name, NIC, phone or email. */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        $like = '%' . addcslashes($term, '%_\\') . '%';

        return $query->where(fn ($q) => $q->where('staff_code', 'like', $like)->orWhere('name', 'like', $like)
            ->orWhere('nic_no', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like));
    }

    /** Next free STF-0001 style code, derived from the row id so it can never collide. */
    public static function createWithCode(array $data): self
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $staff = (new static)->forceFill($data + ['staff_code' => 'TMP-' . uniqid()]);
            $staff->save();
            $staff->forceFill(['staff_code' => sprintf('STF-%04d', $staff->id)])->save();

            return $staff;
        });
    }
}
