<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyProfile extends Model
{
    protected $table = 'company_profile';

    protected $fillable = ['name', 'tagline', 'address', 'phone', 'email', 'tax_no', 'registration_no', 'currency', 'logo_path'];

    /** The one settings row — created (with the migration's column defaults) on first access. Not firstOrCreate(['id' => 1], ...): 'id' isn't fillable, so that would silently drop it and rely on auto-increment happening to land on 1. */
    public static function current(): self
    {
        return static::first() ?? static::create([]);
    }
}
