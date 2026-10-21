<?php

namespace App\Models;

use App\Enums\RedemptionState;
use Database\Factories\DiscountRedemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['discount_id', 'order_id', 'user_id', 'email', 'amount_kobo', 'delivery_waived_kobo', 'state'])]
class DiscountRedemption extends Model
{
    /** @use HasFactory<DiscountRedemptionFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['state' => RedemptionState::class];
    }

    /** @return BelongsTo<Discount, $this> */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
