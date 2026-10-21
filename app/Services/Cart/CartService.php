<?php

namespace App\Services\Cart;

use App\Exceptions\CommerceException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Commerce\CommerceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartService
{
    public const TOKEN_HEADER = 'X-Cart-Token';

    public function __construct(private CommerceSettings $settings) {}

    /**
     * The cart for this request: the signed-in customer's, otherwise the guest cart named by the token header.
     * Ownership is decided here only; callers never look carts or items up by id from client input.
     */
    public function resolve(Request $request, bool $create = false): ?Cart
    {
        $user = $request->user('sanctum');

        if ($user instanceof User) {
            $cart = Cart::firstWhere('user_id', $user->id);

            return $cart ?? ($create ? $this->create($user) : null);
        }

        $token = (string) $request->header(self::TOKEN_HEADER);
        if (Str::isUuid($token)) {
            $cart = Cart::where('token', $token)->whereNull('user_id')->first();
            if ($cart) {
                return $cart;
            }
        }

        return $create ? $this->create(null) : null;
    }

    public function add(Cart $cart, ProductVariant $variant, int $quantity): CartItem
    {
        $this->assertPurchasable($variant);

        return DB::transaction(function () use ($cart, $variant, $quantity): CartItem {
            $item = $cart->items()->where('product_variant_id', $variant->id)->lockForUpdate()->first();
            $target = ($item?->quantity ?? 0) + $quantity;
            $this->assertQuantityAllowed($variant, $target);

            if ($item) {
                $item->update(['quantity' => $target]);
            } else {
                $item = $cart->items()->create([
                    'product_variant_id' => $variant->id,
                    'quantity' => $target,
                    'price_when_added_kobo' => $variant->effectivePriceKobo(),
                ]);
            }
            $this->touch($cart);

            return $item;
        });
    }

    public function setQuantity(CartItem $item, int $quantity): CartItem
    {
        $variant = $item->variant()->with('product')->firstOrFail();
        $this->assertPurchasable($variant);
        $this->assertQuantityAllowed($variant, $quantity);

        $item->update(['quantity' => $quantity]);
        $this->touch($item->cart);

        return $item;
    }

    public function remove(CartItem $item): void
    {
        $cart = $item->cart;
        $item->delete();
        $this->touch($cart);
    }

    /** Folds a guest cart into the customer's cart after login. Quantities add up, capped per line. Orders the guest placed move to the account, so they stay reachable. */
    public function merge(Cart $guest, User $user): Cart
    {
        return DB::transaction(function () use ($guest, $user): Cart {
            $target = Cart::firstWhere('user_id', $user->id) ?? $this->create($user);
            $cap = $this->settings->maxQuantityPerLine();

            foreach ($guest->items as $item) {
                $existing = $target->items()->where('product_variant_id', $item->product_variant_id)->first();
                if ($existing) {
                    $existing->update(['quantity' => min($cap, $existing->quantity + $item->quantity)]);
                } else {
                    $target->items()->create([
                        'product_variant_id' => $item->product_variant_id,
                        'quantity' => min($cap, $item->quantity),
                        'price_when_added_kobo' => $item->price_when_added_kobo,
                    ]);
                }
            }

            Order::where('cart_id', $guest->id)->whereNull('user_id')->update(['user_id' => $user->id, 'cart_id' => $target->id]);

            $guest->delete();
            $this->touch($target);

            return $target;
        });
    }

    /** Called right after login/registration: if the request carries a guest cart token, merge that cart in. */
    public function mergeGuestCartFromRequest(Request $request, User $user): void
    {
        $token = (string) $request->header(self::TOKEN_HEADER);
        if (! Str::isUuid($token)) {
            return;
        }

        $guest = Cart::where('token', $token)->whereNull('user_id')->first();
        if ($guest) {
            $this->merge($guest, $user);
        }
    }

    private function create(?User $user): Cart
    {
        return Cart::create(['token' => (string) Str::uuid(), 'user_id' => $user?->id, 'last_activity_at' => now()]);
    }

    private function touch(Cart $cart): void
    {
        $cart->forceFill(['last_activity_at' => now()])->save();
    }

    private function assertPurchasable(ProductVariant $variant): void
    {
        $variant->loadMissing('product');
        if (! $variant->isPurchasable()) {
            throw new CommerceException('variant_unavailable', 'This item is no longer available.', 422);
        }
    }

    private function assertQuantityAllowed(ProductVariant $variant, int $quantity): void
    {
        $cap = $this->settings->maxQuantityPerLine();
        if ($quantity > $cap) {
            throw new CommerceException('quantity_limit', "You can order at most {$cap} of one item.", 422, ['max' => $cap]);
        }

        $available = $variant->available();
        if ($quantity > $available) {
            throw new CommerceException(
                'insufficient_stock',
                $available === 0 ? 'This item is out of stock.' : "Only {$available} left in stock.",
                422,
                ['available' => $available],
            );
        }
    }
}
