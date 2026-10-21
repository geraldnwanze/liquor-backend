<?php

namespace App\Models;

use App\Enums\OrderFulfilment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'user_id', 'type', 'from_value', 'to_value', 'note', 'customer_visible'])]
class OrderEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['customer_visible' => 'boolean'];
    }

    /** What the customer sees for a visible event. Internal wording (notes, who did it) is never used here. */
    public function customerLabel(): string
    {
        return match (true) {
            $this->type === 'placed' => 'Order placed',
            $this->type === 'paid' => 'Payment received',
            $this->type === 'expired' => 'Reservation expired',
            $this->type === 'cancelled' => 'Order cancelled',
            $this->type === 'fulfilment' && $this->to_value !== null => OrderFulfilment::tryFrom($this->to_value)?->label() ?? 'Order updated',
            default => 'Order updated',
        };
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
