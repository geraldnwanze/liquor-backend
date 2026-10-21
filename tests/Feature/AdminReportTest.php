<?php

use App\Enums\ProductStatus;
use App\Enums\RedemptionState;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\User;
use App\Services\Orders\OrderLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    configureGateways();
    Http::preventStrayRequests();
    ikejaZone(); // delivery fee ₦1,500
});

/** A paid order for `$quantity` of `$variant`, marked paid at the given UTC moment. Total = price x quantity + ₦1,500. */
function paidOn(ProductVariant $variant, int $quantity, string $paidAtUtc, bool $paid = true): Order
{
    $token = addToCart($variant, $quantity);
    $order = Order::firstWhere('reference', checkout($token)->assertCreated()->json('data.reference'));
    if ($paid) {
        app(OrderLifecycle::class)->markPaid($order);
        $order->forceFill(['paid_at' => $paidAtUtc])->save();
    }

    return $order->refresh();
}

function reportsVariant(int $priceKobo = 1_000_000, array $over = []): ProductVariant
{
    return sellableVariant(['price_kobo' => $priceKobo, 'on_hand' => 500, ...$over]);
}

function reports(string $path, array $query = []): TestResponse
{
    return asStaff(orderStaff('store-manager'))->getJson("/api/v1/admin/reports/{$path}".($query ? '?'.http_build_query($query) : ''));
}

describe('who can see reports', function () {
    it('is for roles with the reports permission', function () {
        foreach (['sales', 'products', 'discounts', 'stock'] as $report) {
            guest()->getJson("/api/v1/admin/reports/{$report}")->assertUnauthorized();
            asStaff(User::factory()->create())->getJson("/api/v1/admin/reports/{$report}")->assertForbidden();
            asStaff(orderStaff('order-manager'))->getJson("/api/v1/admin/reports/{$report}")->assertForbidden();
            asStaff(orderStaff('customer-support'))->getJson("/api/v1/admin/reports/{$report}")->assertForbidden();
            asStaff(orderStaff('marketing-manager'))->getJson("/api/v1/admin/reports/{$report}")->assertOk();
            asStaff(orderStaff('store-manager'))->getJson("/api/v1/admin/reports/{$report}")->assertOk();
        }
    });
});

describe('sales over time', function () {
    it('counts paid orders by the day they were paid in Nigerian time, not UTC', function () {
        $v = reportsVariant();
        paidOn($v, 1, '2026-03-10 22:30:00'); // 23:30 on the 10th in Lagos
        paidOn($v, 2, '2026-03-10 23:30:00'); // 00:30 on the 11th in Lagos
        paidOn($v, 1, '2026-03-12 09:00:00');

        $res = reports('sales', ['from' => '2026-03-10', 'to' => '2026-03-12'])->assertOk();
        $byDay = collect($res->json('data.series'))->keyBy('period');

        expect($byDay->keys()->all())->toBe(['2026-03-10', '2026-03-11', '2026-03-12'])
            ->and($byDay['2026-03-10']['orders'])->toBe(1)->and($byDay['2026-03-10']['gross']['kobo'])->toBe(1_150_000)
            ->and($byDay['2026-03-11']['orders'])->toBe(1)->and($byDay['2026-03-11']['gross']['kobo'])->toBe(2_150_000)
            ->and($byDay['2026-03-12']['orders'])->toBe(1);
        $res->assertJsonPath('data.totals.orders', 3)->assertJsonPath('data.totals.gross.kobo', 1_150_000 + 2_150_000 + 1_150_000)
            ->assertJsonPath('data.totals.items.kobo', 4_000_000)->assertJsonPath('data.totals.delivery.kobo', 450_000)
            ->assertJsonPath('data.totals.average_order.kobo', intdiv(4_450_000, 3));
    });

    it('includes the whole of both end dates and nothing outside them', function () {
        $v = reportsVariant();
        paidOn($v, 1, '2026-03-09 22:59:59'); // 23:59:59 on the 9th Lagos: before the range
        paidOn($v, 1, '2026-03-09 23:00:00'); // 00:00:00 on the 10th: first moment in range
        paidOn($v, 1, '2026-03-10 22:59:59'); // 23:59:59 on the 10th: last moment in range
        paidOn($v, 1, '2026-03-10 23:00:00'); // 00:00:00 on the 11th: after the range

        reports('sales', ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertOk()->assertJsonPath('data.totals.orders', 2);
    });

    it('leaves out orders that are not paid, were cancelled after paying, or expired', function () {
        $v = reportsVariant();
        paidOn($v, 1, '2026-03-10 10:00:00');
        paidOn($v, 1, '2026-03-10 10:00:00', paid: false); // awaiting payment
        $cancelled = paidOn($v, 1, '2026-03-10 11:00:00');
        app(OrderLifecycle::class)->cancelPaid($cancelled, null, 'test');

        reports('sales', ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertOk()->assertJsonPath('data.totals.orders', 1)->assertJsonPath('data.totals.gross.kobo', 1_150_000);
    });

    it('shows zero for quiet days instead of skipping them', function () {
        paidOn(reportsVariant(), 1, '2026-03-10 10:00:00');

        $series = reports('sales', ['from' => '2026-03-08', 'to' => '2026-03-12'])->json('data.series');

        expect(collect($series)->pluck('orders')->all())->toBe([0, 0, 1, 0, 0])->and($series[0]['gross']['kobo'])->toBe(0);
    });

    it('takes off processed refunds only, and shows discounts given', function () {
        $order = paidOn(reportsVariant(), 2, '2026-03-10 10:00:00');
        $order->forceFill(['discount_kobo' => 100_000, 'total_kobo' => $order->total_kobo - 100_000])->save();
        $payment = Payment::factory()->create(['order_id' => $order->id]);
        foreach ([['processed', 300_000], ['pending', 111_000], ['failed', 222_000]] as [$state, $amount]) {
            $refund = new Refund(['payment_id' => $payment->id, 'order_id' => $order->id, 'idempotency_key' => "k-{$state}-0123456789", 'amount_kobo' => $amount, 'reason' => 'test']);
            $refund->state = $state;
            $refund->save();
        }

        $res = reports('sales', ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertOk();

        $res->assertJsonPath('data.totals.refunded.kobo', 300_000)->assertJsonPath('data.totals.discounts.kobo', 100_000)
            ->assertJsonPath('data.totals.net.kobo', $order->total_kobo - 300_000)->assertJsonPath('data.series.0.net.kobo', $order->total_kobo - 300_000);
    });

    it('groups by week (starting Monday) and by month', function () {
        $v = reportsVariant();
        paidOn($v, 1, '2026-03-04 10:00:00'); // Wednesday, week of 2 Mar
        paidOn($v, 1, '2026-03-08 10:00:00'); // Sunday, still the week of 2 Mar
        paidOn($v, 1, '2026-03-09 10:00:00'); // Monday, week of 9 Mar
        paidOn($v, 1, '2026-04-02 10:00:00');

        $weeks = collect(reports('sales', ['from' => '2026-03-02', 'to' => '2026-03-15', 'group' => 'week'])->json('data.series'));
        $months = collect(reports('sales', ['from' => '2026-03-01', 'to' => '2026-04-30', 'group' => 'month'])->json('data.series'));

        expect($weeks->pluck('period')->all())->toBe(['2026-03-02', '2026-03-09'])->and($weeks->pluck('orders')->all())->toBe([2, 1]);
        expect($months->pluck('period')->all())->toBe(['2026-03-01', '2026-04-01'])->and($months->pluck('orders')->all())->toBe([3, 1]);
    });

    it('defaults to the last 30 days ending today in Nigerian time', function () {
        CarbonImmutable::setTestNow('2026-05-20 23:30:00'); // already 00:30 on the 21st in Lagos
        $res = reports('sales')->assertOk();

        $res->assertJsonPath('data.range.to', '2026-05-21')->assertJsonPath('data.range.from', '2026-04-22')->assertJsonPath('data.range.group', 'day');
        expect($res->json('data.series'))->toHaveCount(30);
        CarbonImmutable::setTestNow();
    });

    it('rejects ranges that make no sense', function (array $query, string $field) {
        reports('sales', $query)->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'an end before the start' => [['from' => '2026-03-10', 'to' => '2026-03-01'], 'to'],
        'more than a year' => [['from' => '2025-01-01', 'to' => '2026-03-01'], 'to'],
        'a badly formed date' => [['from' => '10/03/2026'], 'from'],
        'an unknown grouping' => [['group' => 'hour'], 'group'],
    ]);

    it('has an empty report for a range with no sales', function () {
        reports('sales', ['from' => '2026-01-01', 'to' => '2026-01-03'])->assertOk()
            ->assertJsonPath('data.totals.orders', 0)->assertJsonPath('data.totals.average_order.kobo', 0)->assertJsonCount(3, 'data.series');
    });
});

describe('top products', function () {
    it('adds up units and revenue per item across paid orders in range, best first', function () {
        $gin = reportsVariant(1_000_000, ['sku' => 'GIN-1']);
        $rum = reportsVariant(6_000_000, ['sku' => 'RUM-1']);
        paidOn($gin, 3, '2026-03-10 10:00:00');
        paidOn($gin, 2, '2026-03-11 10:00:00');
        paidOn($rum, 1, '2026-03-10 10:00:00');
        paidOn($gin, 9, '2026-03-10 10:00:00', paid: false);
        paidOn($gin, 9, '2026-04-20 10:00:00'); // out of range
        $cancelled = paidOn($gin, 7, '2026-03-10 10:00:00'); // paid on a day in range, then cancelled: keeps its paid date
        app(OrderLifecycle::class)->cancelPaid($cancelled, null, 'test');

        $rows = reports('products', ['from' => '2026-03-10', 'to' => '2026-03-11'])->assertOk()->json('data.products');

        expect(collect($rows)->pluck('sku')->all())->toBe(['RUM-1', 'GIN-1'])
            ->and($rows[1]['units'])->toBe(5)->and($rows[1]['orders'])->toBe(2)->and($rows[1]['revenue']['kobo'])->toBe(5_000_000)
            ->and($rows[0]['revenue']['kobo'])->toBe(6_000_000);
    });

    it('can rank by units instead, and limits the list', function () {
        $gin = reportsVariant(1_000_000, ['sku' => 'GIN-1']);
        $rum = reportsVariant(9_000_000, ['sku' => 'RUM-1']);
        paidOn($gin, 5, '2026-03-10 10:00:00'); // most units, less money
        paidOn($rum, 1, '2026-03-10 10:00:00'); // fewest units, most money

        $byRevenue = reports('products', ['from' => '2026-03-10', 'to' => '2026-03-10'])->json('data.products');
        $byUnits = reports('products', ['from' => '2026-03-10', 'to' => '2026-03-10', 'sort' => 'units'])->json('data.products');
        $one = reports('products', ['from' => '2026-03-10', 'to' => '2026-03-10', 'limit' => 1])->json('data.products');

        expect($byRevenue[0]['sku'])->toBe('RUM-1')->and($byUnits[0]['sku'])->toBe('GIN-1')->and($one)->toHaveCount(1);
        reports('products', ['limit' => 500])->assertUnprocessable();
        reports('products', ['sort' => 'profit'])->assertUnprocessable();
    });

    it('keeps a sold item in the report with the name it had when sold', function () {
        $v = reportsVariant(1_000_000, ['sku' => 'OLD-1']);
        paidOn($v, 1, '2026-03-10 10:00:00');
        $v->product->update(['name' => 'Renamed Later']);

        $row = reports('products', ['from' => '2026-03-10', 'to' => '2026-03-10'])->json('data.products.0');

        expect($row['product'])->not->toBe('Renamed Later');
    });
});

describe('discount usage', function () {
    it('totals what each code gave away on paid orders in range, ignoring unpaid and released uses', function () {
        $v = reportsVariant();
        $ten = Discount::factory()->create(['code' => 'TEN']);
        $ship = Discount::factory()->freeDelivery()->create(['code' => 'SHIP']);
        $one = paidOn($v, 1, '2026-03-10 10:00:00');
        $two = paidOn($v, 2, '2026-03-10 11:00:00');
        $three = paidOn($v, 1, '2026-03-10 12:00:00');
        $pending = paidOn($v, 1, '2026-03-10 13:00:00', paid: false);
        $outside = paidOn($v, 1, '2026-05-10 10:00:00');
        $make = fn (Discount $d, Order $o, int $amount, int $waived, RedemptionState $state) => DiscountRedemption::factory()->create([
            'discount_id' => $d->id, 'order_id' => $o->id, 'amount_kobo' => $amount, 'delivery_waived_kobo' => $waived, 'state' => $state,
        ]);
        $make($ten, $one, 100_000, 0, RedemptionState::Redeemed);
        $make($ten, $two, 200_000, 0, RedemptionState::Redeemed);
        $make($ship, $three, 0, 150_000, RedemptionState::Redeemed);
        $make($ten, $pending, 999_000, 0, RedemptionState::Reserved);
        $make($ten, $outside, 888_000, 0, RedemptionState::Redeemed);
        $released = paidOn($v, 1, '2026-03-10 14:00:00');
        $make($ten, $released, 777_000, 0, RedemptionState::Released); // a given-back use must never count, even on a paid order

        $res = reports('discounts', ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertOk();
        $rows = collect($res->json('data.discounts'))->keyBy('code');

        expect($rows->keys()->all())->toBe(['TEN', 'SHIP'])
            ->and($rows['TEN']['uses'])->toBe(2)->and($rows['TEN']['given']['kobo'])->toBe(300_000)->and($rows['TEN']['revenue']['kobo'])->toBe($one->total_kobo + $two->total_kobo)
            ->and($rows['SHIP']['delivery_waived']['kobo'])->toBe(150_000);
        $res->assertJsonPath('data.totals.uses', 3)->assertJsonPath('data.totals.given.kobo', 300_000)->assertJsonPath('data.totals.delivery_waived.kobo', 150_000);
    });

    it('is empty when nothing was used', function () {
        reports('discounts', ['from' => '2026-03-10', 'to' => '2026-03-10'])->assertOk()->assertJsonPath('data.totals.uses', 0)->assertJsonCount(0, 'data.discounts');
    });
});

describe('low stock', function () {
    it('lists items at or below their alert level, lowest first, counting held stock as unavailable', function () {
        $fine = reportsVariant(1_000_000, ['sku' => 'FINE', 'on_hand' => 50, 'low_stock_threshold' => 5]);
        $low = reportsVariant(1_000_000, ['sku' => 'LOW', 'on_hand' => 4, 'low_stock_threshold' => 5]);
        $held = reportsVariant(1_000_000, ['sku' => 'HELD', 'on_hand' => 10, 'reserved' => 8, 'low_stock_threshold' => 5]); // 2 available
        $out = reportsVariant(1_000_000, ['sku' => 'OUT', 'on_hand' => 0, 'low_stock_threshold' => 5]);

        $res = reports('stock')->assertOk();

        expect(collect($res->json('data.variants'))->pluck('sku')->all())->toBe(['OUT', 'HELD', 'LOW'])
            ->and($res->json('data.variants.1.available'))->toBe(2)
            ->and($res->json('data.counts'))->toBe(['on_sale' => 4, 'low' => 3, 'out_of_stock' => 1]);
        expect($fine->refresh()->available())->toBe(50);
    });

    it('ignores items that are not for sale', function () {
        reportsVariant(1_000_000, ['sku' => 'INACTIVE', 'on_hand' => 0, 'is_active' => false]);
        $draft = Product::factory()->create(['status' => ProductStatus::Draft]);
        ProductVariant::factory()->for($draft)->create(['sku' => 'DRAFT', 'on_hand' => 0]);

        reports('stock')->assertOk()->assertJsonPath('data.counts.on_sale', 0)->assertJsonCount(0, 'data.variants');
    });
});
