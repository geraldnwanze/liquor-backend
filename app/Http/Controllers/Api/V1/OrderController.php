<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderAccess;
use App\Services\Orders\ReceiptBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(private OrderAccess $access) {}

    /** Minimal public status, addressed by the unguessable reference. Rate limited against enumeration. */
    public function status(Order $order): JsonResponse
    {
        return response()->json(['data' => [
            'reference' => $order->reference,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
        ]]);
    }

    /**
     * Full order details for the person who placed it: the signed-in owner, or the guest holding the cart token that
     * created the order. Anyone else gets a 404, indistinguishable from an unknown reference.
     */
    public function lookup(Request $request, Order $order): OrderResource
    {
        $owns = $this->access->owns($request, $order);

        abort_unless($owns, 404);

        return new OrderResource($order->load('items', 'events', 'shipment'));
    }

    /** The customer's receipt for a paid order. Same ownership rule as the order itself. */
    public function receipt(Request $request, Order $order, ReceiptBuilder $receipts): JsonResponse
    {
        abort_unless($this->access->owns($request, $order), 404);

        return response()->json(['data' => $receipts->build($order)]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return OrderResource::collection($request->user()->orders()->with('items')->latest('id')->paginate(15));
    }

    public function show(Request $request, string $reference): OrderResource
    {
        // Scoped to the signed-in customer: someone else's order is a 404, indistinguishable from a missing one.
        return new OrderResource($request->user()->orders()->with('items', 'events', 'shipment')->where('reference', $reference)->firstOrFail());
    }
}
