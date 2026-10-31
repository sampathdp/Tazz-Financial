<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Financial history is voided, never deleted: a voided row stays in the table (with who/when/why) so the audit
 * trail shows both the mistake and its correction, while every normal query keeps seeing only live rows.
 * Reach the voided ones with withoutGlobalScope('live') / withVoided().
 */
trait HasVoidedRows
{
    protected static function bootHasVoidedRows(): void
    {
        static::addGlobalScope('live', function (Builder $query) {
            $query->whereNull($query->getModel()->getTable() . '.voided_at');
        });
    }

    public function scopeWithVoided(Builder $query): Builder
    {
        return $query->withoutGlobalScope('live');
    }

    public function markVoided(?int $userId, ?string $reason = null): void
    {
        $this->forceFill(['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason])->save();
    }
}
