<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['carrier', 'tracking_reference', 'carrier_phone', 'notes', 'estimated_delivery_at'])]
class Shipment extends Model
{
    use HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['estimated_delivery_at' => 'datetime', 'dispatched_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
