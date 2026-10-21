<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\OrderPaidNotification;
use App\Services\Administration\SettingsRepository;
use App\Services\Orders\OrderLifecycle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/** Answers successive Paystack verify calls with successive payloads (a second Http::fake() would not replace the first). */
function paystackVerifySequence(Payment $payment, array ...$payloads): void
{
    $i = 0;
    Http::fake(['api.paystack.co/transaction/verify/*' => function () use ($payment, $payloads, &$i) {
        return paystackVerifyResponse($payment, $payloads[min($i++, count($payloads) - 1)]);
    }]);
}

beforeEach(function () {
    configureGateways();
    Http::preventStrayRequests();
});

describe('which payment methods are offered', function () {
    it('lists only gateways that are switched on and have credentials', function () {
        config(['payments.gateways.paystack.secret_key' => null, 'payments.gateways.flutterwave.secret_key' => null]);
        $this->getJson('/api/v1/payments/gateways')->assertOk()->assertExactJson(['data' => []]);

        configureGateways();
        $this->getJson('/api/v1/payments/gateways')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', 'paystack');

        app(SettingsRepository::class)->set('payments', 'enabled_gateways', ['flutterwave']);
        $this->getJson('/api/v1/payments/gateways')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'flutterwave');
    });

    it('never exposes credentials', function () {
        expect($this->getJson('/api/v1/payments/gateways')->getContent())->not->toContain(PAYSTACK_SECRET)->not->toContain(FLW_SECRET)->not->toContain(FLW_HASH);
    });

    it('never offers the development simulator in production, even when enabled', function () {
        config(['payments.gateways.fake.enabled' => true]);
        expect(collect($this->getJson('/api/v1/payments/gateways')->json('data'))->pluck('id'))->toContain('fake');

        $this->app->detectEnvironment(fn () => 'production');
        expect(collect($this->getJson('/api/v1/payments/gateways')->json('data'))->pluck('id'))->not->toContain('fake');
    });
});

describe('starting a payment', function () {
    it('initialises Paystack with the stored order total in kobo and our own reference', function () {
        [$order, $token] = payableOrder(2);

        $payment = initPayment($order, $token);

        Http::assertSent(function (Request $r) use ($order, $payment) {
            return $r->url() === 'https://api.paystack.co/transaction/initialize'
                && $r->hasHeader('Authorization', 'Bearer '.PAYSTACK_SECRET)
                && $r['amount'] === (string) $order->total_kobo
                && $r['currency'] === 'NGN'
                && $r['email'] === $order->email
                && $r['reference'] === $payment->reference
                && $r['callback_url'] === 'https://shop.test/payment/return/'.$payment->reference
                && json_decode($r['metadata'], true) === ['order_reference' => $order->reference];
        });
        expect($payment->amount_kobo)->toBe($order->total_kobo)->and($payment->state)->toBe(PaymentState::Pending)->and($payment->reference)->toStartWith('LQP');
    });

    it('ignores any amount the browser sends', function () {
        [$order, $token] = payableOrder();
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);

        $res = guest($token)->postJson("/api/v1/orders/{$order->reference}/payments", ['gateway' => 'paystack', 'amount' => 1, 'amount_kobo' => 1, 'total_kobo' => 1])->assertCreated();

        $res->assertJsonPath('data.amount.kobo', $order->total_kobo);
        expect(Payment::firstWhere('reference', $res->json('data.reference'))->amount_kobo)->toBe($order->total_kobo);
    });

    it('initialises Flutterwave in naira (major units), not kobo', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token, 'flutterwave');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.flutterwave.com/v3/payments'
            && $r->hasHeader('Authorization', 'Bearer '.FLW_SECRET)
            && $r['amount'] === number_format($order->total_kobo / 100, 2, '.', '')
            && $r['tx_ref'] === $payment->reference
            && $r['currency'] === 'NGN'
            && $r['redirect_url'] === 'https://shop.test/payment/return/'.$payment->reference
            && $r['customer']['email'] === $order->email);
        expect($payment->authorization_url)->toBe('https://checkout.flutterwave.com/v3/hosted/pay/flwlnk-abc');
    });

    it('returns the gateway page and nothing sensitive', function () {
        [$order, $token] = payableOrder();
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'access_code' => 'SECRETISH']])]);

        $res = guest($token)->postJson("/api/v1/orders/{$order->reference}/payments", ['gateway' => 'paystack'])->assertCreated();

        $res->assertJsonPath('data.authorization_url', 'https://checkout.paystack.com/abc')->assertJsonPath('data.state', 'pending');
        expect($res->getContent())->not->toContain(PAYSTACK_SECRET)->not->toContain('SECRETISH')->not->toContain('access_code');
    });

    it('is a retry-safe: asking again returns the same pending payment without another gateway call', function () {
        [$order, $token] = payableOrder();
        $first = initPayment($order, $token);

        $again = guest($token)->postJson("/api/v1/orders/{$order->reference}/payments", ['gateway' => 'paystack'])->assertCreated();

        expect($again->json('data.reference'))->toBe($first->reference)->and(Payment::count())->toBe(1);
        Http::assertSentCount(1);
    });

    it('gives the customer a fresh payment window', function () {
        [$order, $token] = payableOrder();
        $order->forceFill(['reservation_expires_at' => now()->addMinutes(2)])->save();

        initPayment($order, $token);

        expect($order->refresh()->reservation_expires_at->isAfter(now()->addMinutes(20)))->toBeTrue();
    });

    it('only lets the owner of the order pay for it', function () {
        [$order, $token] = payableOrder();
        $stranger = addToCart(sellableVariant());
        Http::fake();

        guest($stranger)->postJson("/api/v1/orders/{$order->reference}/payments", ['gateway' => 'paystack'])->assertNotFound();
        guest()->postJson("/api/v1/orders/{$order->reference}/payments", ['gateway' => 'paystack'])->assertNotFound();
        Http::assertNothingSent();
        expect(Payment::count())->toBe(0);
    });

    it('lets a signed-in owner pay for their own order', function () {
        ikejaZone();
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/cart/items', ['variant_id' => sellableVariant()->id, 'quantity' => 1]);
        $reference = $this->actingAs($user, 'sanctum')->withHeader('Idempotency-Key', 'pay-key-0123456789ab')->postJson('/api/v1/checkout', checkoutPayload())->json('data.reference');
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/orders/{$reference}/payments", ['gateway' => 'paystack'])->assertCreated();
    });

    it('refuses unavailable or unknown gateways before calling anyone', function () {
        [$order, $token] = payableOrder();
        config(['payments.gateways.flutterwave.secret_key' => null]);
        Http::fake();

        foreach (['flutterwave', 'stripe', 'fake'] as $gateway) {
            guest($token)->postJson("/api/v1/orders/{$order->reference}/payments", ['gateway' => $gateway])->assertUnprocessable()->assertJsonPath('code', 'gateway_unavailable');
        }
        guest($token)->postJson("/api/v1/orders/{$order->reference}/payments", [])->assertUnprocessable();
        Http::assertNothingSent();
    });

    it('refuses to take payment for orders that are paid, expired or past their reservation', function () {
        [$paid, $paidToken] = payableOrder();
        app(OrderLifecycle::class)->markPaid($paid);
        guest($paidToken)->postJson("/api/v1/orders/{$paid->reference}/payments", ['gateway' => 'paystack'])->assertConflict()->assertJsonPath('code', 'order_not_payable');

        [$expired, $expiredToken] = payableOrder();
        app(OrderLifecycle::class)->expire($expired);
        guest($expiredToken)->postJson("/api/v1/orders/{$expired->reference}/payments", ['gateway' => 'paystack'])->assertConflict()->assertJsonPath('code', 'order_not_payable');

        [$late, $lateToken] = payableOrder();
        $late->forceFill(['reservation_expires_at' => now()->subMinute()])->save();
        guest($lateToken)->postJson("/api/v1/orders/{$late->reference}/payments", ['gateway' => 'paystack'])->assertConflict()->assertJsonPath('code', 'order_expired');
    });

    it('reports a gateway outage cleanly, records it, and leaves the order payable', function () {
        [$order, $token] = payableOrder();
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Internal error'], 500)]);

        $res = guest($token)->postJson("/api/v1/orders/{$order->reference}/payments", ['gateway' => 'paystack'])->assertStatus(502);

        $res->assertJsonPath('code', 'gateway_error');
        expect($res->getContent())->not->toContain(PAYSTACK_SECRET)->not->toContain('Internal error');
        $payment = Payment::first();
        expect($payment->state)->toBe(PaymentState::Failed)->and($payment->authorization_url)->toBeNull()->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
        expect($payment->attempts()->where('type', 'initialize')->where('outcome', 'error')->exists())->toBeTrue();
    });

    it('reports an unreachable gateway the same way', function () {
        [$order, $token] = payableOrder();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        guest($token)->postJson("/api/v1/orders/{$order->reference}/payments", ['gateway' => 'paystack'])->assertStatus(502)->assertJsonPath('code', 'gateway_error');
    });
});

describe('verifying a payment (the return page)', function () {
    it('marks the order paid only after the gateway confirms, commits stock, and sends the confirmation', function () {
        Notification::fake();
        [$order, $token] = payableOrder(3, ['on_hand' => 10]);
        $payment = initPayment($order, $token);
        Http::fake(["api.paystack.co/transaction/verify/{$payment->reference}" => paystackVerifyResponse($payment)]);

        verifyPayment($payment, $token)->assertOk()->assertJsonPath('data.state', 'succeeded')->assertJsonPath('data.order.status', 'paid');

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === "https://api.paystack.co/transaction/verify/{$payment->reference}" && $r->hasHeader('Authorization', 'Bearer '.PAYSTACK_SECRET));
        $payment->refresh();
        expect($payment->state)->toBe(PaymentState::Succeeded)->and($payment->gateway_transaction_id)->toBe('4099260516')->and($payment->channel)->toBe('card')->and($payment->paid_at)->not->toBeNull();
        $variant = ProductVariant::first();
        expect($variant->on_hand)->toBe(7)->and($variant->reserved)->toBe(0);
        expect(InventoryMovement::where('reason', 'sale')->count())->toBe(1);
        expect($order->refresh()->status)->toBe(OrderStatus::Paid)->and($order->paid_at)->not->toBeNull();
        Notification::assertSentOnDemand(OrderPaidNotification::class);
    });

    it('removes the purchased lines from the cart', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment)]);

        verifyPayment($payment, $token)->assertOk();

        guest($token)->getJson('/api/v1/cart')->assertJsonPath('data.item_count', 0);
    });

    it('is idempotent: verifying again changes nothing and does not call the gateway', function () {
        [$order, $token] = payableOrder(2, ['on_hand' => 10]);
        $payment = initPayment($order, $token);
        Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment)]);

        verifyPayment($payment, $token)->assertOk();
        verifyPayment($payment, $token)->assertOk()->assertJsonPath('data.state', 'succeeded');
        verifyPayment($payment, $token)->assertOk();

        Http::assertSentCount(1); // exactly one verify call (the first); settled payments are never re-asked
        expect(ProductVariant::first()->on_hand)->toBe(8)->and(InventoryMovement::where('reason', 'sale')->count())->toBe(1);
    });

    it('verifies Flutterwave payments by reference and converts naira back to kobo exactly', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token, 'flutterwave');
        Http::fake(['api.flutterwave.com/v3/transactions/verify_by_reference*' => flutterwaveVerifyResponse($payment)]);

        verifyPayment($payment, $token)->assertOk()->assertJsonPath('data.state', 'succeeded');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.flutterwave.com/v3/transactions/verify_by_reference') && $r['tx_ref'] === $payment->reference);
        expect($payment->refresh()->gateway_transaction_id)->toBe('288200108')->and($order->refresh()->status)->toBe(OrderStatus::Paid);
    });

    it('treats a Paystack amount that includes fees as correct when the requested amount matches', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment, ['amount' => $payment->amount_kobo + 10283])]);

        verifyPayment($payment, $token)->assertOk()->assertJsonPath('data.state', 'succeeded');
    });

    it('never fulfils an order when the amount differs, and parks the money for staff', function (string $gateway, array $over) {
        [$order, $token] = payableOrder(1, ['on_hand' => 10]);
        $payment = initPayment($order, $token, $gateway);
        Http::fake($gateway === 'paystack'
            ? ['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment, $over)]
            : ['api.flutterwave.com/v3/transactions/*' => flutterwaveVerifyResponse($payment, $over)]);

        $res = verifyPayment($payment, $token)->assertOk();

        $res->assertJsonPath('data.state', 'review');
        expect($res->json('data.message'))->toContain('do not pay again');
        expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
        expect(ProductVariant::first()->on_hand)->toBe(10)->and(InventoryMovement::where('reason', 'sale')->count())->toBe(0);
        expect($payment->refresh()->state)->toBe(PaymentState::Review)->and(AuditLog::where('action', 'payment.needs_review')->count())->toBe(1);
        expect($res->getContent())->not->toContain('amount_mismatch')->not->toContain('currency_mismatch');
    })->with([
        'paystack underpaid' => ['paystack', ['requested_amount' => 100, 'amount' => 100]],
        'paystack overpaid' => ['paystack', ['requested_amount' => 99_999_999, 'amount' => 99_999_999]],
        'paystack wrong currency' => ['paystack', ['currency' => 'USD']],
        'paystack other reference' => ['paystack', ['reference' => 'SOMEONE-ELSES-REF']],
        'flutterwave underpaid' => ['flutterwave', ['amount' => 1]],
        'flutterwave overpaid' => ['flutterwave', ['amount' => 999999]],
        'flutterwave wrong currency' => ['flutterwave', ['currency' => 'GHS']],
        'flutterwave other tx_ref' => ['flutterwave', ['tx_ref' => 'SOMEONE-ELSES-REF']],
    ]);

    it('records why a payment needs review', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment, ['requested_amount' => 5, 'amount' => 5])]);

        verifyPayment($payment, $token);

        expect($payment->refresh()->review_reason)->toBe('amount_mismatch');
    });

    it('records a failed payment, leaves the order payable, and lets a later success go through', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        paystackVerifySequence($payment, ['status' => 'failed', 'gateway_response' => 'Declined'], []);

        verifyPayment($payment, $token)->assertOk()->assertJsonPath('data.state', 'failed');
        expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);

        $this->travel(10)->seconds();
        verifyPayment($payment, $token)->assertOk()->assertJsonPath('data.state', 'succeeded');
        expect($order->refresh()->status)->toBe(OrderStatus::Paid);
    });

    it('records abandoned payments and keeps pending ones pending', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        $calls = 0;
        Http::fake(['api.paystack.co/transaction/verify/*' => function () use ($payment, &$calls) {
            return match ($calls++) {
                0 => paystackVerifyResponse($payment, ['status' => 'ongoing']),
                1 => Http::response(['status' => false, 'message' => 'Transaction reference not found'], 400),
                default => paystackVerifyResponse($payment, ['status' => 'abandoned']),
            };
        }]);

        verifyPayment($payment, $token)->assertJsonPath('data.state', 'pending');
        $this->travel(10)->seconds();
        verifyPayment($payment, $token)->assertJsonPath('data.state', 'pending');
        $this->travel(10)->seconds();
        verifyPayment($payment, $token)->assertJsonPath('data.state', 'abandoned');
        expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('stays pending (never errors, never pays) when the gateway is down', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['message' => 'Bad gateway'], 502)]);

        verifyPayment($payment, $token)->assertOk()->assertJsonPath('data.state', 'pending');

        expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)->and($payment->attempts()->where('type', 'verify')->where('outcome', 'error')->exists())->toBeTrue();
    });

    it('does not let a browser poll hammer the gateway', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment, ['status' => 'ongoing'])]);

        verifyPayment($payment, $token);
        verifyPayment($payment, $token);
        verifyPayment($payment, $token);

        Http::assertSentCount(1); // a single verify call for three polls
    });

    it('ignores anything the browser claims: only the gateway decides', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment, ['status' => 'abandoned'])]);

        guest($token)->postJson("/api/v1/payments/{$payment->reference}/verify?status=success&trxref={$payment->reference}", ['status' => 'success', 'paid' => true])
            ->assertOk()->assertJsonPath('data.state', 'abandoned');
        expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('only shows a payment to the customer who owns the order', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        $stranger = addToCart(sellableVariant());

        guest($stranger)->postJson("/api/v1/payments/{$payment->reference}/verify")->assertNotFound();
        guest()->postJson("/api/v1/payments/{$payment->reference}/verify")->assertNotFound();
        guest($token)->postJson('/api/v1/payments/LQPNOSUCHPAYMENT/verify')->assertNotFound();
    });

    it('flags a second payment for the same order as a duplicate to refund', function () {
        [$order, $token] = payableOrder(1, ['on_hand' => 10]);
        $first = initPayment($order, $token);
        // A second payment row (e.g. the customer opened two gateway tabs) for the same order.
        $second = Payment::create(['order_id' => $order->id, 'gateway' => 'paystack', 'reference' => 'LQPSECONDPAYMENT01', 'amount_kobo' => $order->total_kobo, 'currency' => 'NGN']);

        Http::fake(['api.paystack.co/transaction/verify/*' => fn (Request $r) => paystackVerifyResponse(str_contains($r->url(), $first->reference) ? $first : $second)]);
        verifyPayment($first, $token)->assertJsonPath('data.state', 'succeeded');
        $this->travel(10)->seconds();
        verifyPayment($second, $token)->assertJsonPath('data.state', 'review');

        expect($second->refresh()->review_reason)->toBe('duplicate_payment')->and(ProductVariant::first()->on_hand)->toBe(9);
    });

    it('fulfils a payment that arrives after the reservation lapsed, if the stock is still there', function () {
        [$order, $token] = payableOrder(2, ['on_hand' => 10]);
        $payment = initPayment($order, $token);
        app(OrderLifecycle::class)->expire($order);
        expect(ProductVariant::first()->reserved)->toBe(0);
        Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment)]);

        verifyPayment($payment, $token)->assertJsonPath('data.state', 'succeeded');

        expect($order->refresh()->status)->toBe(OrderStatus::Paid)->and(ProductVariant::first()->on_hand)->toBe(8)->and(ProductVariant::first()->reserved)->toBe(0);
        expect(InventoryMovement::where('reason', 'sale')->first()->note)->toContain('paid after reservation expired');
    });

    it('parks a late payment for refund when the stock has gone', function () {
        [$order, $token] = payableOrder(3, ['on_hand' => 3]);
        $payment = initPayment($order, $token);
        app(OrderLifecycle::class)->expire($order);
        ProductVariant::first()->forceFill(['on_hand' => 1])->save(); // someone else bought it meanwhile
        Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment)]);

        verifyPayment($payment, $token)->assertJsonPath('data.state', 'review');

        expect($payment->refresh()->review_reason)->toBe('late_payment_unfulfillable')->and($order->refresh()->status)->toBe(OrderStatus::Expired)->and(ProductVariant::first()->on_hand)->toBe(1);
    });
});
