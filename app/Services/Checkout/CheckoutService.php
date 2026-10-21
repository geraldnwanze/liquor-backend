<?php

namespace App\Services\Checkout;

use App\Contracts\AgeVerifier;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CommerceException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Commerce\CommerceSettings;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\StockReservations;
use App\Services\Orders\OrderLifecycle;
use App\Services\Promotions\DiscountEngine;
use App\Services\Promotions\DiscountLine;
use App\Services\Promotions\DiscountRedemptions;
use App\Services\Shipping\DeliveryZoneResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private CommerceSettings $settings,
        private DeliveryZoneResolver $zones,
        private StockReservations $stock,
        private OrderLifecycle $lifecycle,
        private AgeVerifier $ageVerifier,
        private DiscountEngine $discounts,
        private DiscountRedemptions $redemptions,
    ) {}

    /**
     * Validates the cart against live data, creates a pending-payment order and reserves stock, atomically.
     * Prices, delivery fee and totals are computed here from the database; nothing monetary comes from the client.
     * Retrying with the same Idempotency-Key returns the original order instead of creating another.
     *
     * @return array{0: Order, 1: bool} the order and whether it is a replay of an earlier request
     */
    public function place(Cart $cart, CheckoutData $data, string $idempotencyKey, ?User $user): array
    {
        if ($replay = $this->findReplay($cart, $idempotencyKey, $data)) {
            return [$replay, true];
        }
        $this->assertNotSuspended($data->email);

        try {
            return [DB::transaction(fn () => $this->create($cart, $data, $idempotencyKey, $user)), false];
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent request with the same key won the race: hand back its order.
            if ($replay = $this->findReplay($cart, $idempotencyKey, $data)) {
                return [$replay, true];
            }
            throw $e;
        }
    }

    /** A suspended customer can't buy as a guest with the same email either. (Deactivated staff are not customers.) */
    private function assertNotSuspended(string $email): void
    {
        $suspended = User::query()->where('email', mb_strtolower(trim($email)))->where('is_active', false)->whereDoesntHave('roles')->exists();
        if ($suspended) {
            throw new CommerceException('account_suspended', 'This account has been suspended. Please contact us for help.', 403);
        }
    }

    private function findReplay(Cart $cart, string $key, CheckoutData $data): ?Order
    {
        $order = Order::with('items')->where('cart_id', $cart->id)->where('idempotency_key', $key)->first();

        if ($order && ! hash_equals($order->request_hash, $data->hash())) {
            throw new CommerceException('idempotency_key_reused', 'This Idempotency-Key was already used with different details.', 409);
        }

        return $order;
    }

    private function create(Cart $cart, CheckoutData $data, string $key, ?User $user): Order
    {
        // Serialise concurrent checkouts of the same cart, then re-check for a replay under the lock.
        Cart::query()->whereKey($cart->id)->lockForUpdate()->first();
        if ($replay = $this->findReplay($cart, $key, $data)) {
            return $replay;
        }

        $items = $cart->items()->get();
        if ($items->isEmpty()) {
            throw new CommerceException('cart_empty', 'Your cart is empty.');
        }

        // A new checkout supersedes any unpaid one for this cart: free its stock before re-validating.
        Order::query()->where('cart_id', $cart->id)->where('status', OrderStatus::PendingPayment)->get()
            ->each(fn (Order $previous) => $this->lifecycle->cancel($previous));

        $quantities = $items->pluck('quantity', 'product_variant_id')->map(fn ($q) => (int) $q)->all();
        $locked = $this->stock->lock(array_keys($quantities));

        $this->assertLinesPayable($quantities, $locked);
        $this->assertOrderLimit($quantities);

        $ageRestricted = $locked->contains(fn ($v) => $v->product->is_age_restricted);
        if ($ageRestricted) {
            $age = $this->ageVerifier->verify($user, $data->ageConfirmed);
            if (! $age->passed) {
                throw new CommerceException('age_not_verified', $age->reason ?? 'Age confirmation is required.', 422, ['method' => $age->method]);
            }
        }

        $zone = $this->zones->forAddress($data->state, $data->city);
        if (! $zone) {
            throw new CommerceException('delivery_unavailable', "We don't deliver to {$data->city}, {$data->state} yet.", 422);
        }

        $subtotal = 0;
        foreach ($quantities as $id => $quantity) {
            $subtotal += $locked[$id]->effectivePriceKobo() * $quantity;
        }
        // The delivery threshold is judged on the subtotal before any discount.
        $fee = $zone->feeFor($subtotal);

        // Checked again here under a lock on the discount: the cart's earlier check is only a preview, and a code
        // that stopped working (expired, used up) must stop the order rather than quietly charge a different total.
        $applied = $data->discountCode === null ? null : $this->discounts->evaluate(
            $data->discountCode,
            array_values($locked->map(fn ($v) => new DiscountLine($v->product_id, $v->product->category_id, $v->effectivePriceKobo() * $quantities[$v->id]))->all()),
            $fee, $user, $data->email, lock: true,
        );
        $discount = $applied?->amountKobo ?? 0;
        $fee -= $applied?->deliveryWaivedKobo ?? 0;

        try {
            $this->stock->hold($locked, $quantities);
        } catch (InsufficientStockException $e) {
            throw new CommerceException('insufficient_stock', $e->getMessage());
        }

        $order = Order::create([
            'reference' => $this->newReference(),
            'user_id' => $user?->id,
            'cart_id' => $cart->id,
            'idempotency_key' => $key,
            'request_hash' => $data->hash(),
            'status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'currency' => 'NGN',
            'subtotal_kobo' => $subtotal,
            'discount_kobo' => $discount,
            'discount_code' => $applied?->discount->code,
            'delivery_fee_kobo' => $fee,
            'total_kobo' => $subtotal - $discount + $fee,
            'customer_name' => $data->name,
            'email' => $data->email,
            'phone' => $data->phone,
            'delivery_zone_id' => $zone->id,
            'delivery_zone_name' => $zone->name,
            'delivery_state' => $data->state,
            'delivery_city' => $data->city,
            'delivery_address' => $data->address,
            'delivery_instructions' => $data->instructions,
            'age_confirmed_at' => $data->ageConfirmed ? now() : null,
            'reservation_expires_at' => now()->addMinutes($this->settings->reservationMinutes()),
        ]);

        foreach ($quantities as $id => $quantity) {
            $variant = $locked[$id];
            $unit = $variant->effectivePriceKobo();
            $order->items()->create([
                'product_variant_id' => $id,
                'sku' => $variant->sku,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->name,
                'unit_price_kobo' => $unit,
                'quantity' => $quantity,
                'line_total_kobo' => $unit * $quantity,
            ]);
        }

        if ($applied) {
            $this->redemptions->reserve($order, $applied, $user, $data->email);
        }

        $order->events()->create(['type' => 'placed', 'to_value' => 'pending_payment', 'customer_visible' => true]);

        return $order->refresh()->load('items');
    }

    /**
     * @param  array<string, int>  $quantities
     * @param  Collection<int, ProductVariant>  $locked
     */
    private function assertLinesPayable(array $quantities, $locked): void
    {
        $cap = $this->settings->maxQuantityPerLine();
        $problems = [];

        foreach ($quantities as $id => $quantity) {
            $variant = $locked->get($id);
            $issues = [];
            if (! $variant || ! $variant->isPurchasable()) {
                $issues[] = 'unavailable';
            } elseif ($variant->available() === 0) {
                $issues[] = 'out_of_stock';
            } elseif ($quantity > $variant->available()) {
                $issues[] = 'insufficient_stock';
            }
            if ($variant && $quantity > $cap) {
                $issues[] = 'over_line_limit';
            }
            if ($issues) {
                $problems[] = ['variant_id' => $id, 'sku' => $variant?->sku, 'issues' => $issues, 'available' => $variant?->available()];
            }
        }

        if ($problems) {
            throw new CommerceException('cart_has_issues', 'Some items in your cart changed. Review your cart and try again.', 422, ['lines' => $problems]);
        }
    }

    /** @param array<string, int> $quantities */
    private function assertOrderLimit(array $quantities): void
    {
        $max = $this->settings->maxUnitsPerOrder();
        if (array_sum($quantities) > $max) {
            throw new CommerceException('order_limit', "Orders are limited to {$max} items. Please reduce your cart.", 422, ['max_units' => $max]);
        }
    }

    private function newReference(): string
    {
        do {
            $ref = 'LQ'.implode('', array_map(
                fn () => self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)],
                range(1, 10),
            ));
        } while (Order::where('reference', $ref)->exists());

        return $ref;
    }
}
