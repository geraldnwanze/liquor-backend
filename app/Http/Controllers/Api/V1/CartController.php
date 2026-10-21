<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CommerceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddCartItemRequest;
use App\Http\Requests\ApplyDiscountRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\DeliveryZone;
use App\Models\ProductVariant;
use App\Services\Cart\CartPricer;
use App\Services\Cart\CartService;
use App\Services\Shipping\DeliveryZoneResolver;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private CartService $carts,
        private CartPricer $pricer,
        private DeliveryZoneResolver $zones,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->carts->resolve($request);

        if (! $cart) {
            return response()->json(['data' => [
                'token' => null, 'items' => [], 'item_count' => 0, 'subtotal' => Money::toArray(0), 'has_issues' => false, 'discount' => null, 'delivery' => null,
            ]]);
        }

        return $this->respond($request, $cart);
    }

    public function addItem(AddCartItemRequest $request): JsonResponse
    {
        $cart = $this->carts->resolve($request, create: true);
        $variant = ProductVariant::with('product')->findOrFail($request->string('variant_id')->toString());
        $this->carts->add($cart, $variant, $request->integer('quantity'));

        return $this->respond($request, $cart, 201);
    }

    public function updateItem(UpdateCartItemRequest $request, string $item): JsonResponse
    {
        $cart = $this->carts->resolve($request) ?? abort(404);
        // Looked up through the caller's own cart, so another customer's item id is simply a 404.
        $this->carts->setQuantity($cart->items()->findOrFail($item), $request->integer('quantity'));

        return $this->respond($request, $cart);
    }

    public function destroyItem(Request $request, string $item): JsonResponse
    {
        $cart = $this->carts->resolve($request) ?? abort(404);
        $this->carts->remove($cart->items()->findOrFail($item));

        return $this->respond($request, $cart);
    }

    /**
     * Applies a code to the caller's cart. The code is checked against the live cart first and only stored if it
     * works right now; it is checked again whenever the cart is read and again at checkout.
     */
    public function applyDiscount(ApplyDiscountRequest $request): JsonResponse
    {
        $cart = $this->carts->resolve($request) ?? throw new CommerceException('cart_empty', 'Add something to your cart first.');
        $snapshot = $this->pricer->snapshot($cart, $this->zoneFor($request));
        if ($snapshot->itemCount === 0) {
            throw new CommerceException('cart_empty', 'Add something to your cart first.');
        }

        $cart->forceFill(['discount_code' => $request->string('code')->toString()])->save();
        $checked = $this->pricer->snapshot($cart->refresh(), $this->zoneFor($request));
        if ($checked->discountProblem !== null) {
            $cart->forceFill(['discount_code' => null])->save();
            throw new CommerceException($checked->discountProblem['code'], $checked->discountProblem['message'], 422, $checked->discountProblem['details']);
        }

        return $this->respond($request, $cart);
    }

    public function removeDiscount(Request $request): JsonResponse
    {
        $cart = $this->carts->resolve($request) ?? abort(404);
        $cart->forceFill(['discount_code' => null])->save();

        return $this->respond($request, $cart);
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->carts->resolve($request);
        $cart?->items()->delete();

        return $cart ? $this->respond($request, $cart) : $this->show($request);
    }

    private function respond(Request $request, Cart $cart, int $status = 200): JsonResponse
    {
        $zone = $this->zoneFor($request);
        $response = (new CartResource($this->pricer->snapshot($cart->refresh(), $zone)))->response()->setStatusCode($status);

        if ($cart->user_id === null) {
            $response->header(CartService::TOKEN_HEADER, $cart->token);
        }

        return $response;
    }

    private function zoneFor(Request $request): ?DeliveryZone
    {
        return $request->filled('state') ? $this->zones->forAddress($request->string('state')->toString(), $request->string('city')->toString()) : null;
    }
}
