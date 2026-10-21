<?php

namespace App\Models;

use Database\Factories\DeliveryZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'state', 'city', 'fee_kobo', 'free_threshold_kobo', 'estimated_days_min', 'estimated_days_max', 'notes', 'is_active', 'sort_order'])]
class DeliveryZone extends Model
{
    /** @use HasFactory<DeliveryZoneFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @param Builder<DeliveryZone> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Fee for an order with this subtotal, honouring the free-delivery threshold. */
    public function feeFor(int $subtotalKobo): int
    {
        if ($this->free_threshold_kobo !== null && $subtotalKobo >= $this->free_threshold_kobo) {
            return 0;
        }

        return $this->fee_kobo;
    }
}
