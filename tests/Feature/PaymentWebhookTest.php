<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\WebhookEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    configureGateways();
    Http::preventStrayRequests();
});

function chargeSuccess(Payment $p, array $data = []): array
{
    return ['event' => 'charge.success', 'data' => array_merge(['id' => 4099260516, 'status' => 'success', 'reference' => $p->reference, 'amount' => $p->amount_kobo, 'currency' => 'NGN'], $data)];
}

function fakePaystackVerify(Payment $p, array $over = []): void
{
    Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($p, $over)]);
}

describe('Paystack webhooks', function () {
    it('settles the order after re-verifying with the gateway', function () {
        [$order, $token] = payableOrder(2, ['on_hand' => 10]);
        $payment = initPayment($order, $token);
        fakePaystackVerify($payment);

        paystackWebhook(chargeSuccess($payment))->assertOk()->assertJson(['received' => true]);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), "/transaction/verify/{$payment->reference}"));
        expect($payment->refresh()->state)->toBe(PaymentState::Succeeded)->and($order->refresh()->status)->toBe(OrderStatus::Paid)->and(ProductVariant::first()->on_hand)->toBe(8);
        expect(WebhookEvent::first()->processed_at)->not->toBeNull();
    });

    it('rejects a missing, wrong or tampered signature and processes nothing', function (string $case) {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        Http::fake();
        $payload = chargeSuccess($payment);
        $raw = json_encode($payload);

        $res = match ($case) {
            'no signature' => paystackWebhook($payload, null),
            'signed with another key' => paystackWebhook($payload, 'sk_test_someone_elses_key'),
            'body changed after signing' => test()->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, PAYSTACK_SECRET),
            ], str_replace((string) $payment->amount_kobo, '1', $raw)),
            'sha256 instead of sha512' => test()->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha256', $raw, PAYSTACK_SECRET),
            ], $raw),
        };

        $res->assertUnauthorized();
        Http::assertNothingSent();
        expect(WebhookEvent::count())->toBe(0)->and($payment->refresh()->state)->toBe(PaymentState::Pending)->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
    })->with(['no signature', 'signed with another key', 'body changed after signing', 'sha256 instead of sha512']);

    it('fails closed when no secret key is configured', function () {
        config(['payments.gateways.paystack.secret_key' => null]);
        [$order] = [null];
        $body = ['event' => 'charge.success', 'data' => ['reference' => 'X', 'id' => 1]];

        // Even a signature made with an empty key must not be accepted.
        paystackWebhook($body, '')->assertUnauthorized();
        paystackWebhook($body, null)->assertUnauthorized();
    });

    it('logs a rejected webhook without its body', function () {
        Log::spy();

        paystackWebhook(['event' => 'charge.success', 'data' => ['reference' => 'SENSITIVE-REF', 'email' => 'victim@example.com']], 'wrong-key')->assertUnauthorized();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $ctx) => str_contains($m, 'invalid signature') && ! str_contains(json_encode($ctx), 'SENSITIVE-REF') && ! str_contains(json_encode($ctx), 'victim@example.com'));
    });

    it('is idempotent: a redelivered event does not settle or decrement stock twice', function () {
        [$order, $token] = payableOrder(2, ['on_hand' => 10]);
        $payment = initPayment($order, $token);
        fakePaystackVerify($payment);

        paystackWebhook(chargeSuccess($payment))->assertOk();
        paystackWebhook(chargeSuccess($payment))->assertOk();
        paystackWebhook(chargeSuccess($payment))->assertOk();

        Http::assertSentCount(1);
        expect(ProductVariant::first()->on_hand)->toBe(8)->and(InventoryMovement::where('reason', 'sale')->count())->toBe(1)->and(WebhookEvent::count())->toBe(1);
    });

    it('never trusts the webhook body: the gateway verification decides', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        // The webhook claims success; the gateway says the customer abandoned it.
        fakePaystackVerify($payment, ['status' => 'abandoned']);

        paystackWebhook(chargeSuccess($payment))->assertOk();

        expect($payment->refresh()->state)->toBe(PaymentState::Abandoned)->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('does not accept a webhook amount: a lying body with a cheap amount cannot underpay', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        // Webhook says the right amount, but the gateway reports the customer only paid 100 kobo.
        fakePaystackVerify($payment, ['requested_amount' => 100, 'amount' => 100]);

        paystackWebhook(chargeSuccess($payment, ['amount' => $payment->amount_kobo]))->assertOk();

        expect($payment->refresh()->state)->toBe(PaymentState::Review)->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('acknowledges events for unknown references without retry storms', function () {
        Http::fake();

        paystackWebhook(['event' => 'charge.success', 'data' => ['reference' => 'NOT-OURS', 'id' => 7, 'amount' => 100, 'currency' => 'NGN']])->assertOk();

        Http::assertNothingSent();
        expect(WebhookEvent::first()->outcome)->toBe('unknown_reference');
    });

    it('acknowledges event types we do not act on', function () {
        paystackWebhook(['event' => 'customeridentification.success', 'data' => ['customer_id' => 1]])->assertOk();
        paystackWebhook(['event' => 'subscription.create', 'data' => []])->assertOk();
        expect(WebhookEvent::count())->toBe(0);
    });

    it('asks the gateway to retry (503) when it cannot verify right now, then succeeds on redelivery', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        $calls = 0;
        Http::fake(['api.paystack.co/transaction/verify/*' => function () use ($payment, &$calls) {
            return $calls++ === 0 ? Http::response(['message' => 'down'], 503) : paystackVerifyResponse($payment);
        }]);

        paystackWebhook(chargeSuccess($payment))->assertStatus(503);
        expect($payment->refresh()->state)->toBe(PaymentState::Pending)->and(WebhookEvent::first()->processed_at)->toBeNull();

        paystackWebhook(chargeSuccess($payment))->assertOk();
        expect($payment->refresh()->state)->toBe(PaymentState::Succeeded)->and($order->refresh()->status)->toBe(OrderStatus::Paid);
    });

    it('verifies against the raw body byte for byte', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        fakePaystackVerify($payment);
        // Unusual spacing and key order must still validate because the signature covers the exact bytes sent.
        $raw = '{ "data" :{"reference":"'.$payment->reference.'","id":1,"status":"success"},   "event":"charge.success" }';

        paystackWebhook([], PAYSTACK_SECRET, $raw)->assertOk();
        expect($payment->refresh()->state)->toBe(PaymentState::Succeeded);
    });

    it('does not let two concurrent deliveries double-settle (race on the claim)', function () {
        [$order, $token] = payableOrder(1, ['on_hand' => 5]);
        $payment = initPayment($order, $token);
        fakePaystackVerify($payment);
        // Simulate the other worker having already inserted the claim row.
        WebhookEvent::create(['gateway' => 'paystack', 'dedupe_key' => sha1("paystack|charge.success|{$payment->reference}|4099260516"), 'event_type' => 'charge.success']);

        paystackWebhook(chargeSuccess($payment))->assertOk();

        expect($payment->refresh()->state)->toBe(PaymentState::Succeeded)->and(WebhookEvent::count())->toBe(1)->and(ProductVariant::first()->on_hand)->toBe(4);
    });
});

describe('Flutterwave webhooks', function () {
    function flwCharge(Payment $p, array $data = []): array
    {
        return ['event' => 'charge.completed', 'data' => array_merge(['id' => 285959875, 'tx_ref' => $p->reference, 'status' => 'successful', 'amount' => $p->amount_kobo / 100, 'currency' => 'NGN'], $data)];
    }

    it('verifies the verif-hash and settles via the transaction id', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token, 'flutterwave');
        Http::fake(['api.flutterwave.com/v3/transactions/285959875/verify' => flutterwaveVerifyResponse($payment, ['id' => 285959875])]);

        flutterwaveWebhook(flwCharge($payment))->assertOk();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.flutterwave.com/v3/transactions/285959875/verify' && $r->hasHeader('Authorization', 'Bearer '.FLW_SECRET));
        expect($payment->refresh()->state)->toBe(PaymentState::Succeeded)->and($payment->gateway_transaction_id)->toBe('285959875')->and($order->refresh()->status)->toBe(OrderStatus::Paid);
    });

    it('rejects a missing or wrong verif-hash, and fails closed with no hash configured', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token, 'flutterwave');
        Http::fake();

        flutterwaveWebhook(flwCharge($payment), null)->assertUnauthorized();
        flutterwaveWebhook(flwCharge($payment), 'wrong-hash')->assertUnauthorized();
        flutterwaveWebhook(flwCharge($payment), FLW_HASH.' ')->assertUnauthorized();

        config(['payments.gateways.flutterwave.secret_hash' => null]);
        flutterwaveWebhook(flwCharge($payment), '')->assertUnauthorized();
        flutterwaveWebhook(flwCharge($payment), 'anything')->assertUnauthorized();

        Http::assertNothingSent();
        expect($payment->refresh()->state)->toBe(PaymentState::Pending)->and(WebhookEvent::count())->toBe(0);
    });

    it('is idempotent: a redelivered event is verified and counted once', function () {
        [$order, $token] = payableOrder(1, ['on_hand' => 10]);
        $payment = initPayment($order, $token, 'flutterwave');
        Http::fake(['api.flutterwave.com/v3/transactions/*' => flutterwaveVerifyResponse($payment, ['id' => 285959875])]);

        flutterwaveWebhook(flwCharge($payment))->assertOk();
        flutterwaveWebhook(flwCharge($payment))->assertOk();

        Http::assertSentCount(1);
        expect(ProductVariant::first()->on_hand)->toBe(9)->and(InventoryMovement::where('reason', 'sale')->count())->toBe(1);
    });

    it('never trusts the body: a forged "successful" webhook for a failed payment does nothing', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token, 'flutterwave');
        Http::fake(['api.flutterwave.com/v3/transactions/*' => flutterwaveVerifyResponse($payment, ['id' => 5, 'status' => 'failed'])]);

        flutterwaveWebhook(flwCharge($payment, ['id' => 5]))->assertOk();

        expect($payment->refresh()->state)->toBe(PaymentState::Failed)->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
    });

    it('ignores events it does not understand', function () {
        flutterwaveWebhook(['event' => 'transfer.completed', 'data' => ['id' => 1]])->assertOk();
        flutterwaveWebhook(['unexpected' => 'shape'])->assertOk();
        expect(WebhookEvent::count())->toBe(0);
    });
});

describe('webhook routing', function () {
    it('only accepts known gateways', function () {
        $this->postJson('/api/v1/webhooks/stripe', [])->assertNotFound();
        $this->postJson('/api/v1/webhooks/fake', [])->assertUnauthorized(); // known name, but unauthenticated
    });

    it('does not accept webhooks from the simulator in production', function () {
        $this->app->detectEnvironment(fn () => 'production');
        config(['payments.gateways.fake.enabled' => true]);

        $this->call('POST', '/api/v1/webhooks/fake', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => hash_hmac('sha256', '{}', config('app.key'))], '{}')->assertNotFound();
    });
});

describe('refund webhooks', function () {
    function refundFixture(string $gateway = 'paystack', int $amount = 1_000_000): array
    {
        [$order, $token] = payableOrder(1, ['on_hand' => 10]);
        $payment = initPayment($order, $token, $gateway);
        $payment->forceFill(['state' => PaymentState::Succeeded, 'gateway_transaction_id' => '4099260516', 'paid_at' => now()])->save();
        $order->forceFill(['status' => OrderStatus::Paid, 'payment_status' => PaymentStatus::Paid])->save();
        $refund = Refund::create(['payment_id' => $payment->id, 'order_id' => $order->id, 'idempotency_key' => 'refund-key-0123456789', 'amount_kobo' => $amount, 'reason' => 'test'])->refresh();
        $refund->forceFill(['gateway_refund_id' => '3018284'])->save();
        $payment->forceFill(['refunded_kobo' => $amount])->save();

        return [$payment, $refund, $order];
    }

    it('updates a Paystack refund when it is processed', function () {
        [$payment, $refund, $order] = refundFixture();

        paystackWebhook(['event' => 'refund.processed', 'data' => [
            'status' => 'processed', 'transaction_reference' => $payment->reference, 'refund_reference' => '132013318360', 'amount' => (string) $refund->amount_kobo, 'currency' => 'NGN',
        ]])->assertOk();

        expect($refund->refresh()->state)->toBe('processed')->and($refund->gateway_refund_id)->toBe('3018284');
    });

    it('returns the money to the refundable balance when a refund fails', function () {
        [$payment, $refund, $order] = refundFixture();

        paystackWebhook(['event' => 'refund.failed', 'data' => [
            'status' => 'failed', 'transaction_reference' => $payment->reference, 'refund_reference' => '132013318360', 'amount' => (string) $refund->amount_kobo, 'currency' => 'NGN',
        ]])->assertOk();

        expect($refund->refresh()->state)->toBe('failed')->and($payment->refresh()->refunded_kobo)->toBe(0)->and($order->refresh()->payment_status)->toBe(PaymentStatus::Paid);
    });

    it('updates a Flutterwave refund notification (flat payload, matched by TransactionId)', function () {
        [$payment, $refund] = refundFixture('flutterwave');

        flutterwaveWebhook(['id' => 89074, 'AmountRefunded' => $refund->amount_kobo / 100, 'status' => 'completed-mpgs', 'FlwRef' => 'flwm3s4m0c1754324273641', 'TransactionId' => 4099260516])->assertOk();

        expect($refund->refresh()->state)->toBe('processed');
    });

    it('ignores a refund notification that matches nothing we asked for', function () {
        [$payment] = refundFixture();

        paystackWebhook(['event' => 'refund.processed', 'data' => ['status' => 'processed', 'transaction_reference' => $payment->reference, 'refund_reference' => '999', 'amount' => '12345', 'currency' => 'NGN']])->assertOk();

        expect(WebhookEvent::first()->outcome)->toBe('unmatched_refund')->and(Refund::first()->state)->toBe('pending');
    });
});
