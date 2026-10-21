<?php

namespace App\Services\Promotions;

use App\Enums\RedemptionState;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\User;

/**
 * Keeps the usage ledger in step with the order. A use is reserved when the order is placed (so two parallel
 * checkouts cannot both take the last use), becomes redeemed when payment is verified, and is released if the
 * order is cancelled or expires. Released uses stop counting against the limits.
 */
class DiscountRedemptions
{
    public function reserve(Order $order, AppliedDiscount $applied, ?User $user, string $email): DiscountRedemption
    {
        return DiscountRedemption::create([
            'discount_id' => $applied->discount->id,
            'order_id' => $order->id,
            'user_id' => $user?->id,
            'email' => mb_strtolower(trim($email)),
            'amount_kobo' => $applied->amountKobo,
            'delivery_waived_kobo' => $applied->deliveryWaivedKobo ?? 0,
            'state' => RedemptionState::Reserved,
        ]);
    }

    public function markRedeemed(Order $order): void
    {
        DiscountRedemption::query()->where('order_id', $order->id)->where('state', '!=', RedemptionState::Redeemed->value)
            ->update(['state' => RedemptionState::Redeemed->value]);
    }

    public function release(Order $order): void
    {
        DiscountRedemption::query()->where('order_id', $order->id)->where('state', '!=', RedemptionState::Released->value)
            ->update(['state' => RedemptionState::Released->value]);
    }
}
