<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;

/**
 * Who may act on an order: the signed-in customer who owns it, or the guest holding the cart token that placed it.
 * Everyone else is told the order does not exist.
 */
class OrderAccess
{
    public function __construct(private CartService $carts) {}

    public function owns(Request $request, Order $order): bool
    {
        $user = $request->user('sanctum');
        if ($user instanceof User && $order->user_id === $user->id) {
            return true;
        }

        $cart = $this->carts->resolve($request);

        return $cart !== null && $order->cart_id === $cart->id;
    }
}
