<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderFulfilment;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderManagement;
use App\Services\Orders\ReceiptBuilder;
use App\Support\Money;
use App\Support\NigerianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(private OrderManagement $orders) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'payment_status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'fulfilment_status' => ['nullable', Rule::enum(OrderFulfilment::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Order::query()->withSum('items as units', 'quantity')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['payment_status'] ?? null, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($f['fulfilment_status'] ?? null, fn ($q, $v) => $q->where('fulfilment_status', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', date('Y-m-d', strtotime($v.' +1 day'))))
            ->when($f['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes(trim($term), '%_\\').'%';
                $phone = NigerianPhone::normalize($term);
                $q->where(fn ($w) => $w
                    ->where('reference', 'like', strtoupper(addcslashes(trim($term), '%_\\')).'%')
                    ->orWhere('email', 'like', $like)->orWhere('customer_name', 'like', $like)
                    ->when($phone, fn ($x) => $x->orWhere('phone', $phone)));
            })
            ->latest('id')->paginate($f['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Order $o) => [
                'reference' => $o->reference,
                'customer' => ['name' => $o->customer_name, 'email' => $o->email],
                'status' => $o->status->value,
                'payment_status' => $o->payment_status->value,
                'fulfilment_status' => $o->fulfilment_status->value,
                'fulfilment_label' => $o->fulfilment_status->label(),
                'units' => (int) $o->units,
                'total' => Money::toArray($o->total_kobo),
                'city' => $o->delivery_city,
                'created_at' => $o->created_at->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Counts for the dashboard: what needs doing now. */
    public function summary(): JsonResponse
    {
        $byStatus = Order::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $byFulfilment = Order::query()->where('status', OrderStatus::Paid)->selectRaw('fulfilment_status, count(*) as n')->groupBy('fulfilment_status')->pluck('n', 'fulfilment_status');

        $startOfToday = now('Africa/Lagos')->startOfDay()->utc();
        $paidToday = Order::query()->where('status', OrderStatus::Paid)->where('paid_at', '>=', $startOfToday);

        return response()->json(['data' => [
            'today' => [
                'orders_placed' => Order::query()->where('created_at', '>=', $startOfToday)->count(),
                'orders_paid' => (clone $paidToday)->count(),
                'sales' => Money::toArray((int) (clone $paidToday)->sum('total_kobo')),
            ],
            'status' => collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)]),
            'fulfilment' => collect(OrderFulfilment::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($byFulfilment[$s->value] ?? 0)]),
            'payments_needing_review' => Payment::where('state', PaymentState::Review)->count(),
        ]]);
    }

    public function show(Order $order): AdminOrderResource
    {
        return new AdminOrderResource($this->loaded($order));
    }

    public function fulfilment(Request $request, Order $order): AdminOrderResource
    {
        $data = $request->validate(['status' => ['required', Rule::enum(OrderFulfilment::class)], 'note' => ['nullable', 'string', 'max:500']]);

        $this->orders->moveFulfilment($order, OrderFulfilment::from($data['status']), $request->user(), isset($data['note']) ? strip_tags($data['note']) : null);

        return new AdminOrderResource($this->loaded($order));
    }

    public function shipment(Request $request, Order $order): AdminOrderResource
    {
        $data = $request->validate([
            'carrier' => ['nullable', 'string', 'max:80'],
            'tracking_reference' => ['nullable', 'string', 'max:80'],
            'carrier_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
            'estimated_delivery_at' => ['nullable', 'date'],
        ]);
        foreach (['carrier', 'tracking_reference', 'notes'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = trim(strip_tags($data[$field]));
            }
        }

        $this->orders->saveShipment($order, $data, $request->user());

        return new AdminOrderResource($this->loaded($order));
    }

    public function note(Request $request, Order $order): AdminOrderResource
    {
        $request->merge(['body' => trim(strip_tags((string) $request->input('body')))]);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:1000']]);
        $this->orders->addNote($order, $data['body'], $request->user());

        return new AdminOrderResource($this->loaded($order));
    }

    public function cancel(Request $request, Order $order): AdminOrderResource
    {
        $request->merge(['reason' => trim(strip_tags((string) $request->input('reason')))]);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $this->orders->cancel($order, $data['reason'], $request->user());

        return new AdminOrderResource($this->loaded($order));
    }

    public function receipt(Order $order, ReceiptBuilder $receipts): JsonResponse
    {
        return response()->json(['data' => $receipts->build($order)]);
    }

    private function loaded(Order $order): Order
    {
        return $order->refresh()->load(['items', 'payments', 'refunds', 'shipment', 'events.user:id,name']);
    }
}
