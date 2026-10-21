<?php

namespace App\Http\Resources;

use App\Enums\DiscountType;
use App\Services\Cart\CartLine;
use App\Services\Cart\CartSnapshot;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property CartSnapshot $resource */
class CartResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $snapshot = $this->resource;

        return [
            // Guests keep this token and send it back as X-Cart-Token. Signed-in customers don't need one.
            'token' => $snapshot->cart->user_id === null ? $snapshot->cart->token : null,
            'items' => array_map(fn (CartLine $line) => [
                'id' => $line->item->id,
                'variant_id' => $line->variant->id,
                'quantity' => $line->quantity,
                'max_quantity' => $line->maxQuantity,
                'unit_price' => Money::toArray($line->unitPriceKobo),
                'line_total' => Money::toArray($line->lineTotalKobo),
                // Set when the live price differs from the price when the item was added.
                'price_changed_from' => $line->priceChangedFromKobo === null ? null : Money::toArray($line->priceChangedFromKobo),
                'issues' => $line->issues,
                'product' => [
                    'slug' => $line->product->slug,
                    'name' => $line->product->name,
                    'image' => $line->product->images->first()?->only(['url', 'alt']),
                ],
                'variant' => $line->variant->only(['sku', 'name', 'volume_ml']),
            ], $snapshot->lines),
            'item_count' => $snapshot->itemCount,
            'subtotal' => Money::toArray($snapshot->subtotalKobo),
            'has_issues' => $snapshot->hasIssues(),
            'discount' => $this->discount($snapshot),
            'delivery' => $this->delivery($snapshot),
        ];
    }

    /** @return array<string, mixed>|null */
    private function discount(CartSnapshot $snapshot): ?array
    {
        $code = $snapshot->cart->discount_code;
        if ($code === null) {
            return null;
        }
        $applied = $snapshot->discount;

        return [
            'code' => $code,
            'applied' => $applied !== null,
            'type' => $applied?->discount->type->value,
            'amount' => Money::toArray($applied?->amountKobo ?? 0),
            // Free delivery is worked out once the delivery area is known.
            'free_delivery' => $applied?->discount->type === DiscountType::FreeDelivery,
            'problem' => $snapshot->discountProblem === null ? null : ['code' => $snapshot->discountProblem['code'], 'message' => $snapshot->discountProblem['message']],
        ];
    }

    /** @return array<string, mixed>|null */
    private function delivery(CartSnapshot $snapshot): ?array
    {
        if ($snapshot->zone === null) {
            return null;
        }
        $zone = $snapshot->zone;

        return [
            'zone' => $zone->name,
            'fee' => Money::toArray($snapshot->effectiveDeliveryFeeKobo() ?? $zone->fee_kobo),
            'fee_waived' => ($snapshot->discount?->deliveryWaivedKobo ?? 0) > 0 ? Money::toArray($snapshot->discount->deliveryWaivedKobo) : null,
            'free_threshold' => $zone->free_threshold_kobo === null ? null : Money::toArray($zone->free_threshold_kobo),
            'estimate' => self::estimate($zone->estimated_days_min, $zone->estimated_days_max),
            // An estimate only: the final fee is recalculated by the server at checkout.
            'is_estimate' => true,
        ];
    }

    public static function estimate(?int $min, ?int $max): ?string
    {
        if ($min === null && $max === null) {
            return null;
        }
        $min ??= $max;
        $max ??= $min;

        return $min === $max ? "{$min} ".($min === 1 ? 'day' : 'days') : "{$min}-{$max} days";
    }
}
