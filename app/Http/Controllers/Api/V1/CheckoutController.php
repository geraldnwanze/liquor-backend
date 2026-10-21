<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CommerceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\OrderResource;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __construct(private CartService $carts, private CheckoutService $checkout) {}

    public function store(CheckoutRequest $request): JsonResponse
    {
        $cart = $this->carts->resolve($request) ?? throw new CommerceException('cart_empty', 'Your cart is empty.');

        [$order, $replayed] = $this->checkout->place(
            $cart, $request->checkoutData()->withDiscountCode($cart->discount_code), $request->string('idempotency_key')->toString(), $request->user('sanctum'),
        );

        // 201 for a new order; 200 when this exact request was already processed (safe retry).
        return (new OrderResource($order))->response()->setStatusCode($replayed ? 200 : 201)
            ->header('Idempotent-Replayed', $replayed ? 'true' : 'false');
    }
}
