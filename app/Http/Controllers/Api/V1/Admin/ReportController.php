<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\RedemptionState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportRangeRequest;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Read-only figures for the shop owner. Everything counts PAID orders by the day they were paid (Nigerian time).
 * An order that was paid and then cancelled has status "cancelled" and is left out entirely, along with its refund;
 * refunds shown here are partial refunds against orders that still count.
 */
class ReportController extends Controller
{
    public function sales(ReportRangeRequest $request): JsonResponse
    {
        $refunded = DB::table('refunds')->where('state', 'processed')
            ->whereIn('order_id', $this->paidOrders($request)->select('orders.id'))
            ->groupBy('order_id')->selectRaw('order_id, sum(amount_kobo) as amount')->pluck('amount', 'order_id');

        $series = $this->emptySeries($request);
        $count = $gross = $items = $discounts = $delivery = 0;

        // Streamed in chunks and folded into running totals, so memory stays flat however many orders were paid.
        $this->paidOrders($request)->select(['id', 'paid_at', 'subtotal_kobo', 'discount_kobo', 'delivery_fee_kobo', 'total_kobo'])->orderBy('id')->lazy(1000)->each(function (Order $order) use (&$series, &$count, &$gross, &$items, &$discounts, &$delivery, $refunded, $request): void {
            $key = $this->bucket(CarbonImmutable::instance($order->paid_at)->setTimezone(ReportRangeRequest::TIMEZONE), $request->group());
            $series[$key]['orders']++;
            $series[$key]['gross'] += $order->total_kobo;
            $series[$key]['discounts'] += $order->discount_kobo;
            $series[$key]['refunded'] += (int) ($refunded[$order->id] ?? 0);
            $count++;
            $gross += $order->total_kobo;
            $items += $order->subtotal_kobo;
            $discounts += $order->discount_kobo;
            $delivery += $order->delivery_fee_kobo;
        });

        $rows = collect($series)->map(fn (array $r, string $key) => [
            'period' => $key,
            'orders' => $r['orders'],
            'gross' => Money::toArray($r['gross']),
            'discounts' => Money::toArray($r['discounts']),
            'refunded' => Money::toArray($r['refunded']),
            'net' => Money::toArray($r['gross'] - $r['refunded']),
        ])->values();

        $refundTotal = (int) $refunded->sum();

        return response()->json(['data' => [
            'range' => $this->range($request),
            'totals' => [
                'orders' => $count,
                'gross' => Money::toArray($gross),
                'items' => Money::toArray($items),
                'discounts' => Money::toArray($discounts),
                'delivery' => Money::toArray($delivery),
                'refunded' => Money::toArray($refundTotal),
                'net' => Money::toArray($gross - $refundTotal),
                // Whole kobo, rounded down: money is never a float here.
                'average_order' => Money::toArray($count > 0 ? intdiv($gross, $count) : 0),
            ],
            'series' => $rows,
        ]]);
    }

    public function products(ReportRangeRequest $request): JsonResponse
    {
        $sort = $request->input('sort', 'revenue');
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', OrderStatus::Paid->value)
            ->where('orders.paid_at', '>=', $request->startUtc())->where('orders.paid_at', '<', $request->endUtc())
            ->groupBy('order_items.sku')
            ->selectRaw('order_items.sku as sku, max(order_items.product_name) as product_name, max(order_items.variant_name) as variant_name, sum(order_items.quantity) as units, sum(order_items.line_total_kobo) as revenue, count(distinct order_items.order_id) as orders')
            ->orderByDesc($sort === 'units' ? 'units' : 'revenue')->orderBy('order_items.sku')
            ->limit((int) $request->input('limit', 10))->get();

        return response()->json(['data' => [
            'range' => $this->range($request),
            'sort' => $sort,
            // Revenue here is the items' own price before any order-level discount code or delivery fee.
            'products' => $rows->map(fn ($r) => [
                'sku' => $r->sku, 'product' => $r->product_name, 'variant' => $r->variant_name,
                'units' => (int) $r->units, 'orders' => (int) $r->orders, 'revenue' => Money::toArray((int) $r->revenue),
            ])->values(),
        ]]);
    }

    public function discounts(ReportRangeRequest $request): JsonResponse
    {
        $rows = DB::table('discount_redemptions')
            ->join('discounts', 'discounts.id', '=', 'discount_redemptions.discount_id')
            ->join('orders', 'orders.id', '=', 'discount_redemptions.order_id')
            ->where('discount_redemptions.state', RedemptionState::Redeemed->value)
            ->where('orders.status', OrderStatus::Paid->value)
            ->where('orders.paid_at', '>=', $request->startUtc())->where('orders.paid_at', '<', $request->endUtc())
            ->groupBy('discounts.id', 'discounts.code', 'discounts.name')
            ->selectRaw('discounts.id as id, discounts.code as code, discounts.name as name, count(*) as uses, sum(discount_redemptions.amount_kobo) as given, sum(discount_redemptions.delivery_waived_kobo) as waived, sum(orders.total_kobo) as revenue')
            ->orderByDesc('uses')->orderBy('discounts.code')->get();

        return response()->json(['data' => [
            'range' => $this->range($request),
            'totals' => [
                'uses' => (int) $rows->sum('uses'),
                'given' => Money::toArray((int) $rows->sum('given')),
                'delivery_waived' => Money::toArray((int) $rows->sum('waived')),
                'revenue' => Money::toArray((int) $rows->sum('revenue')),
            ],
            'discounts' => $rows->map(fn ($r) => [
                'id' => (string) $r->id, 'code' => $r->code, 'name' => $r->name, 'uses' => (int) $r->uses,
                'given' => Money::toArray((int) $r->given), 'delivery_waived' => Money::toArray((int) $r->waived),
                // What those orders brought in: the total the customers paid.
                'revenue' => Money::toArray((int) $r->revenue),
            ])->values(),
        ]]);
    }

    /** Variants that are on sale and at or below their low-stock level. Not date-bound: it is about now. */
    public function stock(): JsonResponse
    {
        $active = ProductVariant::query()->where('is_active', true)->whereHas('product', fn ($q) => $q->where('status', 'active'));
        $low = (clone $active)->with('product:id,name,slug')
            ->whereRaw('(on_hand - reserved) <= low_stock_threshold')
            ->orderByRaw('(on_hand - reserved)')->orderBy('id')->limit(100)->get();

        return response()->json(['data' => [
            'counts' => [
                'on_sale' => (clone $active)->count(),
                'low' => (clone $active)->whereRaw('(on_hand - reserved) <= low_stock_threshold')->count(),
                'out_of_stock' => (clone $active)->whereRaw('(on_hand - reserved) <= 0')->count(),
            ],
            'variants' => $low->map(fn (ProductVariant $v) => [
                'id' => $v->id, 'product_id' => $v->product_id, 'product' => $v->product->name, 'variant' => $v->name, 'sku' => $v->sku,
                'available' => $v->available(), 'on_hand' => $v->on_hand, 'reserved' => $v->reserved, 'threshold' => $v->low_stock_threshold,
            ])->values(),
        ]]);
    }

    /** @return Builder<Order> */
    private function paidOrders(ReportRangeRequest $request): Builder
    {
        return Order::query()->where('status', OrderStatus::Paid)->where('paid_at', '>=', $request->startUtc())->where('paid_at', '<', $request->endUtc());
    }

    /** @return array<string, mixed> */
    private function range(ReportRangeRequest $request): array
    {
        return ['from' => $request->localStart()->toDateString(), 'to' => $request->localEnd()->toDateString(), 'group' => $request->group(), 'timezone' => ReportRangeRequest::TIMEZONE];
    }

    /** One zeroed row per day, week or month in the range, so quiet periods show as zero instead of vanishing. @return array<string, array<string, int>> */
    private function emptySeries(ReportRangeRequest $request): array
    {
        $series = [];
        $cursor = $request->localStart();
        $end = $request->localEnd();
        while ($cursor->lte($end)) {
            $series[$this->bucket($cursor, $request->group())] ??= ['orders' => 0, 'gross' => 0, 'discounts' => 0, 'refunded' => 0];
            $cursor = $cursor->addDay();
        }

        return $series;
    }

    /** Day: the date. Week: the Monday it starts on. Month: the first of the month. All in Nigerian time. */
    private function bucket(CarbonImmutable $local, string $group): string
    {
        return match ($group) {
            'week' => $local->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            'month' => $local->startOfMonth()->toDateString(),
            default => $local->toDateString(),
        };
    }
}
