<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Stamps a `public_uuid` on create for models that get referenced by
 * external-facing URLs/APIs. The internal, sequential `id` stays the primary
 * key everywhere (joins, indexes, InnoDB clustering all want that) — this is
 * only for the places a sequential id would leak count/growth information or
 * be trivially enumerable (a public loan lookup link, a customer-facing
 * statement URL). Applied so far to User; Customer, the credit-facility
 * tables and FixedDeposit pick it up in later phases once those tables are
 * redesigned.
 */
trait HasPublicUuid
{
    protected static function bootHasPublicUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_uuid)) {
                $model->public_uuid = (string) Str::uuid();
            }
        });
    }
}
