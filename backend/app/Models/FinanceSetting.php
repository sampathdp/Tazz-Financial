<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Small key/value store for Finance-wide toggles — see the
 * create_finance_settings_and_cash_sessions migration. Read via the static
 * helpers, which cache for the request.
 */
class FinanceSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    public $timestamps = true;

    private const CACHE_KEY = 'finance_settings:all';

    /** {key: value} for every setting, cached for the request. */
    public static function map(): \Illuminate\Support\Collection
    {
        return Cache::remember(self::CACHE_KEY, 60, fn () => static::query()->pluck('value', 'key'));
    }

    public static function get(string $key, $default = null)
    {
        return self::map()->get($key, $default);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);

        return $v === null ? $default : in_array((string) $v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function put(string $key, $value, ?int $userId = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value, 'updated_by' => $userId]);
        Cache::forget(self::CACHE_KEY);
    }
}
