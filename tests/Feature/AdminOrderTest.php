<?php

use App\Enums\OrderFulfilment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Notifications\OrderFulfilmentNotification;
use App\Services\Administration\SettingsRepository;
use App\Services\Orders\OrderLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    configureGateways();
    Http::preventStrayRequests();
});

/** A paid order, reached through the same lifecycle a verified payment uses. @return array{0: Order, 1: string} */
function paidOrder(int $quantity = 1, array $variant = []): array
{
    [$order, $token] = payableOrder($quantity, $variant);
    app(OrderLifecycle::class)->markPaid($order);

    return [$order->refresh(), $token];
}

/** A paid order backed by a real succeeded payment (so refunds have something to refund). @return array{0: Order, 1: string} */
function paidOrderWithPayment(int $quantity = 1): array
{
    [$order, $token] = payableOrder($quantity, ['on_hand' => 20]);
    $payment = initPayment($order, $token);
    Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment)]);
    verifyPayment($payment, $token)->assertJsonPath('data.state', 'succeeded');
    resetHttpRecording();

    return [$order->refresh(), $token];
}

describe('who can see and change orders', function () {
    it('keeps orders away from the public, customers and unrelated staff', function () {
        [$order] = paidOrder();

        $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
        asStaff(User::factory()->create())->getJson('/api/v1/admin/orders')->assertForbidden();
        asStaff(orderStaff('content-editor'))->getJson('/api/v1/admin/orders')->assertForbidden();
        asStaff(orderStaff('product-manager'))->getJson("/api/v1/admin/orders/{$order->reference}")->assertForbidden();
    });

    it('lets customer support look but not change, and order managers do both', function () {
        [$order] = paidOrder();
        $support = orderStaff('customer-support');

        asStaff($support)->getJson('/api/v1/admin/orders')->assertOk();
        asStaff($support)->getJson("/api/v1/admin/orders/{$order->reference}")->assertOk();
        asStaff($support)->getJson("/api/v1/admin/orders/{$order->reference}/receipt")->assertOk();
        asStaff($support)->patchJson("/api/v1/admin/orders/{$order->reference}/fulfilment", ['status' => 'processing'])->assertForbidden();
        asStaff($support)->putJson("/api/v1/admin/orders/{$order->reference}/shipment", ['carrier' => 'X'])->assertForbidden();
        asStaff($support)->postJson("/api/v1/admin/orders/{$order->reference}/notes", ['body' => 'hello'])->assertForbidden();
        asStaff($support)->postJson("/api/v1/admin/orders/{$order->reference}/cancel", ['reason' => 'no reason'])->assertForbidden();

        asStaff(orderStaff('order-manager'))->patchJson("/api/v1/admin/orders/{$order->reference}/fulfilment", ['status' => 'processing'])->assertOk();
    });

    it('has no way for staff to mark an order paid', function () {
        [$order] = payableOrder();
        $manager = orderStaff('store-manager');

        asStaff($manager)->patchJson("/api/v1/admin/orders/{$order->reference}", ['status' => 'paid', 'payment_status' => 'paid'])->assertStatus(405);
        asStaff($manager)->patchJson("/api/v1/admin/orders/{$order->reference}/fulfilment", ['status' => 'processing', 'payment_status' => 'paid', 'order_status' => 'paid'])
            ->assertConflict()->assertJsonPath('code', 'order_not_fulfillable');
        expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)->and($order->payment_status)->toBe(PaymentStatus::Pending);
    });
});

describe('listing and searching', function () {
    it('lists orders newest first with the details staff need', function () {
        [$a] = paidOrder(2);
        [$b] = payableOrder();

        $res = asStaff(orderStaff('order-manager'))->getJson('/api/v1/admin/orders')->assertOk();

        $res->assertJsonPath('data.0.reference', $b->reference)->assertJsonPath('data.1.reference', $a->reference)
            ->assertJsonPath('data.1.units', 2)->assertJsonPath('data.1.fulfilment_status', 'unfulfilled')->assertJsonPath('data.1.total.kobo', $a->total_kobo)
            ->assertJsonPath('meta.total', 2);
    });

    it('filters by status, payment status, fulfilment and dates', function () {
        [$paid] = paidOrder();
        [$pending] = payableOrder();
        [$shipped] = paidOrder();
        $shipped->forceFill(['fulfilment_status' => OrderFulfilment::OutForDelivery])->save();
        $manager = orderStaff('order-manager');

        asStaff($manager)->getJson('/api/v1/admin/orders?status=pending_payment')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $pending->reference);
        asStaff($manager)->getJson('/api/v1/admin/orders?status=paid')->assertJsonCount(2, 'data');
        asStaff($manager)->getJson('/api/v1/admin/orders?fulfilment_status=out_for_delivery')->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $shipped->reference);
        asStaff($manager)->getJson('/api/v1/admin/orders?payment_status=pending')->assertJsonCount(1, 'data');
        asStaff($manager)->getJson('/api/v1/admin/orders?from='.now()->addDay()->toDateString())->assertJsonCount(0, 'data');
        asStaff($manager)->getJson('/api/v1/admin/orders?from='.now()->toDateString().'&to='.now()->toDateString())->assertJsonCount(3, 'data');
        asStaff($manager)->getJson('/api/v1/admin/orders?status=bogus')->assertUnprocessable();
        asStaff($manager)->getJson('/api/v1/admin/orders?to=2020-01-01&from=2024-01-01')->assertUnprocessable();
    });

    it('searches by reference, name, email and phone in any format', function () {
        [$order] = payableOrder();
        $order->forceFill(['customer_name' => 'Chidinma Okeke', 'email' => 'chidi@example.com', 'phone' => '+2348031234567'])->save();
        [$other] = payableOrder();
        $other->forceFill(['phone' => '+2348099990000'])->save();
        $manager = orderStaff('order-manager');

        foreach ([$order->reference, strtolower(substr($order->reference, 0, 6)), 'Chidinma', 'chidi@', '0803 123 4567', '+234 803 123 4567'] as $term) {
            asStaff($manager)->getJson('/api/v1/admin/orders?q='.urlencode($term))->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $order->reference);
        }
        asStaff($manager)->getJson('/api/v1/admin/orders?q=nobody-like-this')->assertJsonCount(0, 'data');
        // A wildcard typed into the box is literal text, not "match everything".
        asStaff($manager)->getJson('/api/v1/admin/orders?q='.urlencode('%'))->assertJsonCount(0, 'data');
    });

    it('does not run a query per order', function () {
        foreach (range(1, 8) as $i) {
            payableOrder();
        }
        $manager = orderStaff('order-manager');
        asStaff($manager);
        DB::enableQueryLog();
        test()->getJson('/api/v1/admin/orders')->assertOk();

        // staff lookup + permission lookup + count + page: independent of how many orders are listed
        expect(count(DB::getQueryLog()))->toBeLessThan(12);
    });

    it('summarises what needs attention', function () {
        paidOrder();
        [$shipped] = paidOrder();
        $shipped->forceFill(['fulfilment_status' => OrderFulfilment::OutForDelivery])->save();
        payableOrder();

        asStaff(orderStaff('order-manager'))->getJson('/api/v1/admin/orders/summary')->assertOk()
            ->assertJsonPath('data.status.paid', 2)->assertJsonPath('data.status.pending_payment', 1)->assertJsonPath('data.status.cancelled', 0)
            ->assertJsonPath('data.fulfilment.unfulfilled', 1)->assertJsonPath('data.fulfilment.out_for_delivery', 1)->assertJsonPath('data.payments_needing_review', 0);
    });

    it('reports today\'s orders and paid sales in Lagos time, not older ones', function () {
        [$todays] = paidOrder();
        [$older] = paidOrder();
        $older->forceFill(['created_at' => now()->subDays(3), 'paid_at' => now()->subDays(3)])->save();

        asStaff(orderStaff('order-manager'))->getJson('/api/v1/admin/orders/summary')->assertOk()
            ->assertJsonPath('data.today.orders_placed', 1)->assertJsonPath('data.today.orders_paid', 1)
            ->assertJsonPath('data.today.sales.kobo', $todays->total_kobo);
    });

    it('shows one order in full, and 404s an unknown reference', function () {
        [$order] = paidOrderWithPayment(2);
        $manager = orderStaff('order-manager');

        $res = asStaff($manager)->getJson("/api/v1/admin/orders/{$order->reference}")->assertOk();

        $res->assertJsonPath('data.status', 'paid')->assertJsonPath('data.customer.email', 'ada@example.com')->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.payments.0.state', 'succeeded')->assertJsonPath('data.next_fulfilment.0.value', 'processing')->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.events.0.type', 'placed')->assertJsonPath('data.events.1.type', 'paid');
        asStaff($manager)->getJson('/api/v1/admin/orders/LQNOSUCHORDER')->assertNotFound();
    });
});

describe('fulfilment', function () {
    it('moves an order through preparation, dispatch and delivery, recording who and when', function () {
        Notification::fake();
        [$order] = paidOrder();
        $manager = orderStaff('order-manager');
        asStaff($manager)->putJson("/api/v1/admin/orders/{$order->reference}/shipment", ['carrier' => 'Own rider: Tunde', 'tracking_reference' => 'TRK-1'])->assertOk();

        asStaff($manager)->patchJson("/api/v1/admin/orders/{$order->reference}/fulfilment", ['status' => 'processing'])->assertOk()->assertJsonPath('data.fulfilment_status', 'processing');
        asStaff($manager)->patchJson("/api/v1/admin/orders/{$order->reference}/fulfilment", ['status' => 'out_for_delivery', 'note' => 'Left the shop at 2pm'])->assertOk();
        expect($order->shipment->refresh()->dispatched_at)->not->toBeNull();
        asStaff($manager)->patchJson("/api/v1/admin/orders/{$order->reference}/fulfilment", ['status' => 'delivered'])->assertOk()->assertJsonPath('data.next_fulfilment', []);

        expect($order->shipment->refresh()->delivered_at)->not->toBeNull();
        $steps = $order->events()->where('type', 'fulfilment')->get();
        expect($steps->pluck('to_value')->all())->toBe(['processing', 'out_for_delivery', 'delivered'])
            ->and($steps[1]->from_value)->toBe('processing')->and($steps[1]->user_id)->toBe($manager->id)->and($steps[1]->note)->toBe('Left the shop at 2pm');
        expect(AuditLog::where('action', 'order.fulfilment_changed')->where('user_id', $manager->id)->count())->toBe(3);
    });

    it('emails the customer when the order leaves, arrives or fails, but not for internal steps', function () {
        Notification::fake();
        [$order] = paidOrder();
        $manager = orderStaff('order-manager');
        $move = fn (string $to) => asStaff($manager)->patchJson("/api/v1/admin/orders/{$order->reference}/fulfilment", ['status' => $to])->assertOk();

        $move('processing');
        Notification::assertNothingSent();
        $move('out_for_delivery');
        Notification::assertSentOnDemandTimes(OrderFulfilmentNotification::class, 1);
        $move('delivery_failed');
        Notification::assertSentOnDemandTimes(OrderFulfilmentNotification::class, 2);
        $move('out_for_delivery');
        $move('delivered');
        Notification::assertSentOnDemandTimes(OrderFulfilmentNotification::class, 4);
    });

    it('allows only sensible moves, and says what is allowed', function (string $from, string $to, bool $ok) {
        [$order] = paidOrder();
        $order->forceFill(['fulfilment_status' => OrderFulfilment::from($from)])->save();

        $res = asStaff(orderStaff('order-manager'))->patchJson("/api/v1/admin/orders/{$order->reference}/fulfilment", ['status' => $to]);

        if ($ok) {
            $res->assertOk();
        } else {
            $res->assertUnprocessable()->assertJsonPath('code', 'invalid_fulfilment_transition')->assertJsonPath('details.from', $from);
            expect($order->refresh()->fulfilment_status->value)->toBe($from);
        }
    })->with([
        'start preparing' => ['unfulfilled', 'processing', true],
        'dispatch' => ['processing', 'out_for_delivery', true],
        'deliver' => ['out_for_delivery', 'delivered', true],
        'fail a delivery' => ['out_for_delivery', 'delivery_failed', true],
        'retry after a failure' => ['delivery_failed', 'out_for_delivery', true],
        'repack after a failure' => ['delivery_failed', 'processing', true],
        'skip preparation' => ['unfulfilled', 'out_for_delivery', false],
        'deliver without dispatching' => ['processing', 'delivered', false],
        'go backwards' => ['out_for_delivery', 'processing', false],
        'reopen a delivered order' => ['delivered', 'out_for_delivery', false],
        'same status again' => ['processing', 'processing', false],
    ]);

    it('refuses unknown statuses and orders that are not paid', function () {
        [$unpaid] = payableOrder();
        [$paid] = paidOrder();
        $manager = orderStaff('order-manager');

        asStaff($manager)->patchJson("/api/v1/admin/orders/{$unpaid->reference}/fulfilment", ['status' => 'processing'])->assertConflict()->assertJsonPath('code', 'order_not_fulfillable');
        asStaff($manager)->patchJson("/api/v1/admin/orders/{$paid->reference}/fulfilment", ['status' => 'teleported'])->assertUnprocessable();
        asStaff($manager)->patchJson("/api/v1/admin/orders/{$paid->reference}/fulfilment", [])->assertUnprocessable();
        expect($unpaid->refresh()->fulfilment_status)->toBe(OrderFulfilment::Unfulfilled);
    });
});

describe('delivery details and notes', function () {
    it('saves delivery details, strips markup, and keeps the carrier phone away from customers', function () {
        [$order, $token] = paidOrder();
        $manager = orderStaff('order-manager');

        asStaff($manager)->putJson("/api/v1/admin/orders/{$order->reference}/shipment", [
            'carrier' => '<b>Own rider</b>', 'tracking_reference' => 'TRK-99', 'carrier_phone' => '0803 999 0000', 'notes' => 'Call on arrival<script>x</script>',
            'estimated_delivery_at' => now()->addDay()->toDateTimeString(),
        ])->assertOk()->assertJsonPath('data.shipment.carrier', 'Own rider')->assertJsonPath('data.shipment.carrier_phone', '0803 999 0000');

        $customer = guest($token)->getJson("/api/v1/orders/{$order->reference}")->assertOk();
        $customer->assertJsonPath('data.shipment.carrier', 'Own rider')->assertJsonPath('data.shipment.tracking_reference', 'TRK-99');
        expect($customer->getContent())->not->toContain('0803 999 0000')->not->toContain('carrier_phone')->not->toContain('Call on arrival');
        expect(Shipment::count())->toBe(1);

        asStaff($manager)->putJson("/api/v1/admin/orders/{$order->reference}/shipment", ['carrier' => 'DHL'])->assertOk();
        expect(Shipment::count())->toBe(1)->and(Shipment::first()->carrier)->toBe('DHL');
    });

    it('only accepts delivery details for paid orders', function () {
        [$unpaid] = payableOrder();

        asStaff(orderStaff('order-manager'))->putJson("/api/v1/admin/orders/{$unpaid->reference}/shipment", ['carrier' => 'X'])->assertConflict();
    });

    it('keeps internal notes internal', function () {
        [$order, $token] = paidOrder();
        $manager = orderStaff('order-manager');

        asStaff($manager)->postJson("/api/v1/admin/orders/{$order->reference}/notes", ['body' => 'Customer asked us to call first<script>alert(1)</script>'])
            ->assertOk()->assertJsonFragment(['note' => 'Customer asked us to call firstalert(1)', 'by' => 'Order-manager Person', 'customer_visible' => false]);

        $customer = guest($token)->getJson("/api/v1/orders/{$order->reference}")->assertOk();
        expect($customer->getContent())->not->toContain('call first')->not->toContain('Order-manager');
        asStaff($manager)->postJson("/api/v1/admin/orders/{$order->reference}/notes", ['body' => ''])->assertUnprocessable();
        asStaff($manager)->postJson("/api/v1/admin/orders/{$order->reference}/notes", ['body' => str_repeat('x', 1001)])->assertUnprocessable();
    });
});

describe('cancelling', function () {
    it('cancels an unpaid order and releases its stock', function () {
        [$order] = payableOrder(3, ['on_hand' => 10]);
        expect(ProductVariant::first()->reserved)->toBe(3);

        asStaff(orderStaff('order-manager'))->postJson("/api/v1/admin/orders/{$order->reference}/cancel", ['reason' => 'Customer called to cancel'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        expect(ProductVariant::first()->reserved)->toBe(0)->and($order->refresh()->cancelled_at)->not->toBeNull();
        expect(AuditLog::where('action', 'order.cancelled')->first()->metadata)->toMatchArray(['reason' => 'Customer called to cancel', 'was' => 'pending_payment']);
    });

    it('cancels a paid, unshipped order: refunds in full first, then puts the stock back', function () {
        [$order] = paidOrderWithPayment(2);
        expect(ProductVariant::first()->on_hand)->toBe(18);
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'Refund has been queued for processing', 'data' => ['id' => 11, 'status' => 'pending']])]);
        $manager = orderStaff('store-manager'); // has the refund permission

        asStaff($manager)->postJson("/api/v1/admin/orders/{$order->reference}/cancel", ['reason' => 'Out of ice'])->assertOk()->assertJsonPath('data.status', 'cancelled');

        Http::assertSent(fn ($r) => $r->url() === 'https://api.paystack.co/refund' && $r['amount'] === $order->total_kobo);
        $order->refresh();
        expect($order->status)->toBe(OrderStatus::Cancelled)->and($order->payment_status)->toBe(PaymentStatus::Refunded);
        expect(ProductVariant::first()->on_hand)->toBe(20);
        expect(InventoryMovement::where('reason', 'order_cancelled')->first())->delta->toBe(2);
        expect($order->payments()->first()->refunded_kobo)->toBe($order->total_kobo);
        $event = $order->events()->where('type', 'cancelled')->latest('id')->first();
        expect($event->note)->toBe('Out of ice')->and($event->customer_visible)->toBeTrue()->and($event->user_id)->toBe($manager->id);
    });

    it('will not cancel a paid order for someone who cannot refund, and changes nothing', function () {
        [$order] = paidOrderWithPayment();
        Http::fake();

        asStaff(orderStaff('order-manager'))->postJson("/api/v1/admin/orders/{$order->reference}/cancel", ['reason' => 'Nope'])
            ->assertForbidden()->assertJsonPath('code', 'refund_permission_required');

        Http::assertNothingSent();
        expect($order->refresh()->status)->toBe(OrderStatus::Paid)->and(ProductVariant::first()->on_hand)->toBe(19);
    });

    it('keeps the order paid and the stock sold when the gateway rejects the refund, and can be retried', function () {
        [$order] = paidOrderWithPayment();
        $calls = 0;
        Http::fake(['api.paystack.co/refund' => function () use (&$calls) {
            return $calls++ === 0 ? Http::response(['status' => false, 'message' => 'Insufficient balance'], 400) : Http::response(['status' => true, 'data' => ['id' => 2, 'status' => 'pending']]);
        }]);
        $manager = orderStaff('store-manager');

        asStaff($manager)->postJson("/api/v1/admin/orders/{$order->reference}/cancel", ['reason' => 'Changed mind'])->assertStatus(502)->assertJsonPath('code', 'refund_failed');
        expect($order->refresh()->status)->toBe(OrderStatus::Paid)->and(ProductVariant::first()->on_hand)->toBe(19);

        asStaff($manager)->postJson("/api/v1/admin/orders/{$order->reference}/cancel", ['reason' => 'Changed mind'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        expect(ProductVariant::first()->on_hand)->toBe(20)->and($order->refresh()->payments()->first()->refunded_kobo)->toBe($order->total_kobo);
    });

    it('refuses to cancel once the order has left, or is already cancelled or expired', function (string $fulfilment) {
        [$order] = paidOrder();
        $order->forceFill(['fulfilment_status' => OrderFulfilment::from($fulfilment)])->save();
        Http::fake();

        asStaff(orderStaff('store-manager'))->postJson("/api/v1/admin/orders/{$order->reference}/cancel", ['reason' => 'Too late'])->assertConflict()->assertJsonPath('code', 'order_not_cancellable');

        Http::assertNothingSent();
        expect($order->refresh()->status)->toBe(OrderStatus::Paid);
    })->with(['out_for_delivery', 'delivered', 'delivery_failed']);

    it('cancels while still being prepared', function () {
        [$order] = paidOrderWithPayment();
        $order->forceFill(['fulfilment_status' => OrderFulfilment::Processing])->save();
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 3, 'status' => 'pending']])]);

        asStaff(orderStaff('store-manager'))->postJson("/api/v1/admin/orders/{$order->reference}/cancel", ['reason' => 'Out of stock'])->assertOk();
    });

    it('refuses to cancel what is already cancelled or expired', function () {
        [$cancelled] = payableOrder();
        app(OrderLifecycle::class)->cancel($cancelled);
        [$expired] = payableOrder();
        app(OrderLifecycle::class)->expire($expired);
        $manager = orderStaff('store-manager');

        asStaff($manager)->postJson("/api/v1/admin/orders/{$cancelled->reference}/cancel", ['reason' => 'again'])->assertConflict();
        asStaff($manager)->postJson("/api/v1/admin/orders/{$expired->reference}/cancel", ['reason' => 'again'])->assertConflict();
        asStaff($manager)->postJson("/api/v1/admin/orders/{$expired->reference}/cancel", [])->assertUnprocessable();
    });
});

describe('what customers see', function () {
    it('shows a tracking timeline of customer-facing steps only, in order', function () {
        [$order, $token] = paidOrder();
        $manager = orderStaff('order-manager');
        asStaff($manager)->postJson("/api/v1/admin/orders/{$order->reference}/notes", ['body' => 'Secret internal note'])->assertOk();
        asStaff($manager)->patchJson("/api/v1/admin/orders/{$order->reference}/fulfilment", ['status' => 'processing', 'note' => 'Internal packing remark'])->assertOk();
        asStaff($manager)->putJson("/api/v1/admin/orders/{$order->reference}/shipment", ['carrier' => 'Rider'])->assertOk();

        $res = guest($token)->getJson("/api/v1/orders/{$order->reference}")->assertOk();

        expect(collect($res->json('data.timeline'))->pluck('label')->all())->toBe(['Order placed', 'Payment received', 'Preparing your order']);
        $res->assertJsonPath('data.fulfilment.status', 'processing')->assertJsonPath('data.fulfilment.label', 'Preparing your order');
        expect($res->getContent())->not->toContain('Secret internal note')->not->toContain('Internal packing remark');
    });

    it('lists permissions for staff (to show or hide screens) and none for customers', function () {
        $staff = orderStaff('customer-support');
        $super = User::factory()->create();
        $super->roles()->attach(Role::where('slug', 'super-admin')->first());

        asStaff($staff)->getJson('/api/v1/me')->assertJsonCount(3, 'data.permissions')->assertJsonFragment(['permissions' => ['customers.manage', 'orders.view', 'reviews.moderate']]);
        asStaff($super)->getJson('/api/v1/me')->assertJsonPath('data.permissions', ['*']);
        asStaff(User::factory()->create())->getJson('/api/v1/me')->assertJsonPath('data.permissions', []);
    });
});

describe('receipts', function () {
    it('gives the paying customer a receipt built from settings, with no card details', function () {
        app(SettingsRepository::class)->set('business', 'name', 'Demo Cellars Ltd');
        app(SettingsRepository::class)->set('business', 'address', '1 Demo Street, Lagos');
        app(SettingsRepository::class)->set('business', 'receipt_footer', 'Drink responsibly.');
        [$order, $token] = paidOrderWithPayment(2);

        $res = guest($token)->getJson("/api/v1/orders/{$order->reference}/receipt")->assertOk();

        $res->assertJsonPath('data.number', $order->reference)->assertJsonPath('data.business.name', 'Demo Cellars Ltd')->assertJsonPath('data.business.address', '1 Demo Street, Lagos')
            ->assertJsonPath('data.footer', 'Drink responsibly.')->assertJsonPath('data.items.0.quantity', 2)->assertJsonPath('data.total.kobo', $order->total_kobo)
            ->assertJsonPath('data.payment.method', 'Paystack (card)')->assertJsonPath('data.status', 'paid')->assertJsonPath('data.refunded.kobo', 0);
        expect($res->getContent())->not->toContain('authorization')->not->toContain('last4')->not->toContain(PAYSTACK_SECRET);
    });

    it('is not available before payment, or to anyone else', function () {
        [$unpaid, $unpaidToken] = payableOrder();
        [$paid] = paidOrder();
        $stranger = addToCart(sellableVariant());

        guest($unpaidToken)->getJson("/api/v1/orders/{$unpaid->reference}/receipt")->assertConflict()->assertJsonPath('code', 'receipt_unavailable');
        guest($stranger)->getJson("/api/v1/orders/{$paid->reference}/receipt")->assertNotFound();
        guest()->getJson("/api/v1/orders/{$paid->reference}/receipt")->assertNotFound();
    });

    it('is available to staff with view access, and shows a cancelled order as cancelled', function () {
        [$order] = paidOrderWithPayment();
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 4, 'status' => 'pending']])]);
        asStaff(orderStaff('store-manager'))->postJson("/api/v1/admin/orders/{$order->reference}/cancel", ['reason' => 'Test'])->assertOk();

        asStaff(orderStaff('customer-support'))->getJson("/api/v1/admin/orders/{$order->reference}/receipt")->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.refunded.kobo', $order->total_kobo);
        asStaff(User::factory()->create())->getJson("/api/v1/admin/orders/{$order->reference}/receipt")->assertForbidden();
    });
});

it('refuses notes and cancellation reasons that are only markup', function () {
    [$order] = paidOrder();
    $manager = orderStaff('order-manager');

    asStaff($manager)->postJson("/api/v1/admin/orders/{$order->reference}/notes", ['body' => '<p></p>'])->assertUnprocessable()->assertJsonValidationErrors('body');
    asStaff($manager)->postJson("/api/v1/admin/orders/{$order->reference}/cancel", ['reason' => '<i> </i>'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect($order->refresh()->events()->where('type', 'note')->count())->toBe(0)->and($order->status->value)->toBe('paid');
});
