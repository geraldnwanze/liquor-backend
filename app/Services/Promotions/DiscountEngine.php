<?php

namespace App\Services\Promotions;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\RedemptionState;
use App\Exceptions\CommerceException;
use App\Models\Category;
use App\Models\Discount;
use App\Models\User;
use App\Support\Money;

/**
 * Decides whether a code applies to a cart and how much it takes off. Pure arithmetic on integer kobo; the client
 * never supplies an amount. One code per order, so there is nothing to stack: the result is the whole discount.
 *
 * Rules, in order: the code exists and is switched on; it has started and not ended; total and per-customer limits;
 * it matches at least one item; the eligible items meet the minimum; it actually changes the price.
 */
class DiscountEngine
{
    public function find(string $code, bool $lock = false): ?Discount
    {
        $query = Discount::query()->where('code', Discount::normaliseCode($code));

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * @param  list<DiscountLine>  $lines  lines that can actually be bought
     * @param  ?int  $deliveryFeeKobo  the delivery fee before any waiver; null while the address is unknown
     * @param  bool  $lock  take a row lock on the discount so limits hold across parallel checkouts
     *
     * @throws CommerceException with a customer-safe message and a machine-readable code
     */
    public function evaluate(string $code, array $lines, ?int $deliveryFeeKobo, ?User $user, ?string $email, bool $lock = false): AppliedDiscount
    {
        $discount = $this->find($code, $lock);
        // Unknown and switched-off codes read the same, so codes cannot be probed.
        if (! $discount || ! $discount->is_active) {
            throw new CommerceException('discount_invalid', "That code isn't valid.");
        }
        if ($discount->starts_at?->isFuture()) {
            throw new CommerceException('discount_not_started', "This code isn't active yet.");
        }
        if ($discount->ends_at?->isPast()) {
            throw new CommerceException('discount_expired', 'This code has expired.');
        }
        if ($discount->usage_limit !== null && $discount->activeUseCount() >= $discount->usage_limit) {
            throw new CommerceException('discount_exhausted', 'This code has been fully used.');
        }
        if ($discount->per_customer_limit !== null && $this->customerUses($discount, $user, $email) >= $discount->per_customer_limit) {
            throw new CommerceException('discount_customer_limit', "You've already used this code.");
        }

        $eligible = $this->eligibleSubtotal($discount, $lines);
        if ($eligible === 0) {
            throw new CommerceException('discount_not_applicable', "This code doesn't apply to the items in your cart.");
        }
        if ($discount->min_order_kobo !== null && $eligible < $discount->min_order_kobo) {
            $needed = Money::format($discount->min_order_kobo);
            throw new CommerceException('discount_min_order', "Spend {$needed} on eligible items to use this code.", 422, ['min_order_kobo' => $discount->min_order_kobo]);
        }

        if ($discount->type === DiscountType::FreeDelivery) {
            if ($deliveryFeeKobo === 0) {
                throw new CommerceException('discount_delivery_already_free', 'Delivery is already free on this order.');
            }

            return new AppliedDiscount($discount, 0, $deliveryFeeKobo);
        }

        $amount = $discount->type === DiscountType::Percentage
            ? intdiv($eligible * $discount->value, 100)
            : $discount->value;
        if ($discount->type === DiscountType::Percentage && $discount->max_discount_kobo !== null) {
            $amount = min($amount, $discount->max_discount_kobo);
        }
        $amount = min($amount, $eligible);
        if ($amount <= 0) {
            throw new CommerceException('discount_not_applicable', "This code doesn't apply to the items in your cart.");
        }

        return new AppliedDiscount($discount, $amount, 0);
    }

    /** @param list<DiscountLine> $lines */
    private function eligibleSubtotal(Discount $discount, array $lines): int
    {
        $matching = match ($discount->scope) {
            DiscountScope::Order => $lines,
            DiscountScope::Products => $this->linesIn($lines, $discount->products()->pluck('products.id')->all(), fn (DiscountLine $l) => $l->productId),
            DiscountScope::Categories => $this->linesIn($lines, $this->categoryIdsWithChildren($discount), fn (DiscountLine $l) => $l->categoryId),
        };

        return array_sum(array_map(fn (DiscountLine $l) => $l->lineTotalKobo, $matching));
    }

    /**
     * @param  list<DiscountLine>  $lines
     * @param  list<string>  $ids
     * @param  callable(DiscountLine): ?int  $key
     * @return list<DiscountLine>
     */
    private function linesIn(array $lines, array $ids, callable $key): array
    {
        return array_values(array_filter($lines, fn (DiscountLine $l) => ($k = $key($l)) !== null && in_array($k, $ids, true)));
    }

    /** A discount on "Spirits" covers Whiskey, Rum and the other sub-categories under it. @return list<string> */
    private function categoryIdsWithChildren(Discount $discount): array
    {
        $ids = $discount->categories()->pluck('categories.id')->all();

        return array_values(array_unique([...$ids, ...Category::query()->whereIn('parent_id', $ids)->pluck('id')->all()]));
    }

    /** Uses by this customer: by account when signed in, and by checkout email for everyone. */
    private function customerUses(Discount $discount, ?User $user, ?string $email): int
    {
        $email = $email !== null ? mb_strtolower(trim($email)) : null;
        if (! $user && ! $email) {
            return 0;
        }

        return $discount->redemptions()
            ->whereIn('state', [RedemptionState::Reserved, RedemptionState::Redeemed])
            ->where(function ($q) use ($user, $email): void {
                if ($user) {
                    $q->orWhere('user_id', $user->id);
                }
                if ($email) {
                    $q->orWhere('email', $email);
                }
            })
            ->count();
    }
}
