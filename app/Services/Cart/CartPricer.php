<?php

namespace App\Services\Cart;

use App\Exceptions\CommerceException;
use App\Models\Cart;
use App\Models\DeliveryZone;
use App\Services\Commerce\CommerceSettings;
use App\Services\Promotions\DiscountEngine;
use App\Services\Promotions\DiscountLine;

/** Prices a cart from live catalogue data. The client's idea of prices or totals is never consulted. */
class CartPricer
{
    public function __construct(private CommerceSettings $settings, private DiscountEngine $discounts) {}

    public function snapshot(Cart $cart, ?DeliveryZone $zone = null): CartSnapshot
    {
        $items = $cart->items()->with(['variant.product.images' => fn ($q) => $q->limit(1)])->get();
        $maxPerLine = $this->settings->maxQuantityPerLine();

        $lines = [];
        $subtotal = 0;
        $count = 0;

        foreach ($items as $item) {
            $variant = $item->variant;
            $product = $variant->product;
            $available = $variant->available();
            $unit = $variant->effectivePriceKobo();

            $issues = [];
            if (! $variant->isPurchasable()) {
                $issues[] = 'unavailable';
            } elseif ($available === 0) {
                $issues[] = 'out_of_stock';
            } elseif ($item->quantity > $available) {
                $issues[] = 'insufficient_stock';
            }
            if ($item->quantity > $maxPerLine && ! in_array('unavailable', $issues, true)) {
                $issues[] = 'over_line_limit';
            }

            $lineTotal = $unit * $item->quantity;
            if (! in_array('unavailable', $issues, true)) {
                $subtotal += $lineTotal;
            }
            $count += $item->quantity;

            $lines[] = new CartLine(
                $item, $variant, $product, $item->quantity, $unit, $lineTotal,
                max(0, min($available, $maxPerLine)), $issues,
                $item->price_when_added_kobo !== $unit ? $item->price_when_added_kobo : null,
            );
        }

        $fee = $zone?->feeFor($subtotal);
        [$applied, $problem] = $this->applyCode($cart, $lines, $fee);

        return new CartSnapshot($cart, $lines, $subtotal, $count, $zone, $fee, $applied, $problem);
    }

    /**
     * Re-checks the code stored on the cart against the live cart. A code that no longer works is reported (so the
     * shopper sees why) but never reduces the price.
     *
     * @param  list<CartLine>  $lines
     * @return array{0: ?AppliedDiscount, 1: ?array{code: string, message: string, details: array<string, mixed>}}
     */
    private function applyCode(Cart $cart, array $lines, ?int $fee): array
    {
        if ($cart->discount_code === null) {
            return [null, null];
        }
        $eligible = array_map(
            fn (CartLine $l) => new DiscountLine($l->product->id, $l->product->category_id, $l->lineTotalKobo),
            array_values(array_filter($lines, fn (CartLine $l) => ! in_array('unavailable', $l->issues, true))),
        );
        $user = $cart->user;
        try {
            return [$this->discounts->evaluate($cart->discount_code, $eligible, $fee, $user, $user?->email), null];
        } catch (CommerceException $e) {
            return [null, ['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details]];
        }
    }
}
