<?php

namespace App\Models;

use App\Enums\OrderFulfilment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'reference', 'user_id', 'cart_id', 'idempotency_key', 'request_hash', 'status', 'payment_status', 'currency',
    'subtotal_kobo', 'discount_kobo', 'discount_code', 'delivery_fee_kobo', 'total_kobo', 'customer_name', 'email', 'phone',
    'delivery_zone_id', 'delivery_zone_name', 'delivery_state', 'delivery_city', 'delivery_address',
    'delivery_instructions', 'age_confirmed_at', 'reservation_expires_at',
])]
class Order extends Model
{
    use HasUuids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'fulfilment_status' => OrderFulfilment::class,
            'age_confirmed_at' => 'datetime',
            'reservation_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function isPendingPayment(): bool
    {
        return $this->status === OrderStatus::PendingPayment;
    }

    /** @return HasMany<OrderItem, $this> */
    /** @return HasOne<DiscountRedemption, $this> */
    public function discountRedemption(): HasOne
    {
        return $this->hasOne(DiscountRedemption::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** @return HasOne<Shipment, $this> */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    /** @return HasMany<OrderEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DeliveryZone, $this> */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'delivery_zone_id');
    }
}
