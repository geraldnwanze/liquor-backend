<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    configureGateways();
    Http::preventStrayRequests();
});

function paymentStaff(string $role): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('slug', $role)->first());

    return $user;
}

/** A genuinely settled payment, reached through the real verify flow. */
function settledPayment(string $gateway = 'paystack', int $quantity = 1): Payment
{
    [$order, $token] = payableOrder($quantity, ['on_hand' => 20]);
    $payment = initPayment($order, $token, $gateway);
    Http::fake($gateway === 'paystack'
        ? ['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment)]
        : ['api.flutterwave.com/v3/transactions/verify_by_reference*' => flutterwaveVerifyResponse($payment)]);
    verifyPayment($payment, $token)->assertJsonPath('data.state', 'succeeded');
    resetHttpRecording(); // tests assert on the requests they cause, not the setup

    return $payment->refresh();
}

function refundRequest(User $by, Payment $payment, array $body = ['reason' => 'Customer changed their mind'], string $key = 'refund-key-0123456789'): TestResponse
{
    return test()->actingAs($by, 'sanctum')->withHeader('Idempotency-Key', $key)->postJson("/api/v1/admin/payments/{$payment->reference}/refunds", $body);
}

describe('who may refund', function () {
    it('requires the refund permission', function () {
        $payment = settledPayment();
        app('auth')->forgetGuards();

        $this->postJson("/api/v1/admin/payments/{$payment->reference}/refunds", ['reason' => 'x y z'])->assertUnauthorized();
        refundRequest(User::factory()->create(), $payment)->assertForbidden();
        refundRequest(paymentStaff('order-manager'), $payment)->assertForbidden();
        refundRequest(paymentStaff('content-editor'), $payment)->assertForbidden();
        Http::assertNothingSent();
        expect(Refund::count())->toBe(0);
    });
});

describe('Paystack refunds', function () {
    it('refunds in full: asks the gateway, records who and why, and updates the order', function () {
        $payment = settledPayment();
        $admin = paymentStaff('store-manager');
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'Refund has been queued for processing', 'data' => ['id' => 3018284, 'status' => 'pending', 'amount' => $payment->amount_kobo]])]);

        $res = refundRequest($admin, $payment)->assertCreated();

        $res->assertJsonPath('data.state', 'pending')->assertJsonPath('data.amount.kobo', $payment->amount_kobo)->assertJsonPath('data.payment_refunded.kobo', $payment->amount_kobo);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.paystack.co/refund'
            && $r->hasHeader('Authorization', 'Bearer '.PAYSTACK_SECRET)
            && $r['transaction'] === $payment->reference && $r['amount'] === $payment->amount_kobo && $r['currency'] === 'NGN');
        $refund = Refund::first();
        expect($refund->requested_by)->toBe($admin->id)->and($refund->reason)->toBe('Customer changed their mind')->and($refund->gateway_refund_id)->toBe('3018284');
        expect($payment->refresh()->refunded_kobo)->toBe($payment->amount_kobo)->and($payment->order->payment_status)->toBe(PaymentStatus::Refunded);
        expect(AuditLog::where('action', 'payment.refund_requested')->where('user_id', $admin->id)->count())->toBe(1);
    });

    it('is idempotent: repeating the same request neither refunds twice nor calls the gateway again', function () {
        $payment = settledPayment();
        $admin = paymentStaff('store-manager');
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 1, 'status' => 'pending']])]);

        refundRequest($admin, $payment)->assertCreated();
        refundRequest($admin, $payment)->assertOk();
        refundRequest($admin, $payment)->assertOk();

        Http::assertSentCount(1);
        expect(Refund::count())->toBe(1)->and($payment->refresh()->refunded_kobo)->toBe($payment->amount_kobo);
    });

    it('supports partial refunds and never lets the total exceed what was paid', function () {
        $payment = settledPayment('paystack', 2);
        $admin = paymentStaff('store-manager');
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 9, 'status' => 'pending']])]);
        $half = intdiv($payment->amount_kobo, 2);

        refundRequest($admin, $payment, ['amount_kobo' => $half, 'reason' => 'Damaged bottle'], 'key-partial-1-0123456')->assertCreated();
        expect($payment->refresh()->refunded_kobo)->toBe($half)->and($payment->order->payment_status)->toBe(PaymentStatus::PartiallyRefunded);

        refundRequest($admin, $payment, ['amount_kobo' => $payment->amount_kobo, 'reason' => 'Too much'], 'key-partial-2-0123456')
            ->assertUnprocessable()->assertJsonPath('code', 'refund_amount_invalid')->assertJsonPath('details.refundable_kobo', $payment->amount_kobo - $half);

        refundRequest($admin, $payment, ['reason' => 'Refund the rest'], 'key-partial-3-0123456')->assertCreated();
        expect($payment->refresh()->refunded_kobo)->toBe($payment->amount_kobo)->and($payment->order->payment_status)->toBe(PaymentStatus::Refunded);

        refundRequest($admin, $payment, ['reason' => 'Again?'], 'key-partial-4-0123456')->assertUnprocessable();
        Http::assertSentCount(2);
    });

    it('validates the request', function () {
        $payment = settledPayment();
        $admin = paymentStaff('store-manager');

        refundRequest($admin, $payment, ['amount_kobo' => 0, 'reason' => 'Bad amount'])->assertUnprocessable();
        refundRequest($admin, $payment, ['amount_kobo' => -5, 'reason' => 'Bad amount'])->assertUnprocessable();
        refundRequest($admin, $payment, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        test()->actingAs($admin, 'sanctum')->withoutHeader('Idempotency-Key')->postJson("/api/v1/admin/payments/{$payment->reference}/refunds", ['reason' => 'No key'])->assertUnprocessable();
        refundRequest($admin, $payment, ['reason' => 'Short key'], 'short')->assertUnprocessable();
        Http::assertNothingSent();
    });

    it('refuses to refund a payment that was never received', function () {
        [$order, $token] = payableOrder();
        $pending = initPayment($order, $token);
        $admin = paymentStaff('store-manager');

        refundRequest($admin, $pending)->assertConflict()->assertJsonPath('code', 'payment_not_refundable');
        $pending->forceFill(['state' => PaymentState::Failed])->save();
        refundRequest($admin, $pending, ['reason' => 'x y z'], 'other-key-0123456789')->assertConflict();
    });

    it('leaves the money refundable when the gateway rejects the refund', function () {
        $payment = settledPayment();
        $admin = paymentStaff('store-manager');
        $calls = 0;
        Http::fake(['api.paystack.co/refund' => function () use (&$calls) {
            return $calls++ === 0 ? Http::response(['status' => false, 'message' => 'Insufficient balance'], 400) : Http::response(['status' => true, 'data' => ['id' => 2, 'status' => 'pending']]);
        }]);

        $res = refundRequest($admin, $payment)->assertStatus(502);

        $res->assertJsonPath('code', 'refund_failed');
        expect($res->getContent())->not->toContain('Insufficient balance');
        expect(Refund::first()->state)->toBe('failed')->and($payment->refresh()->refunded_kobo)->toBe(0)->and($payment->order->payment_status)->toBe(PaymentStatus::Paid);

        refundRequest($admin, $payment, ['reason' => 'Retry later'], 'second-key-0123456789')->assertCreated();
        expect($payment->refresh()->refunded_kobo)->toBe($payment->amount_kobo);
    });
});

describe('Flutterwave refunds', function () {
    it('refunds by transaction id in naira', function () {
        $payment = settledPayment('flutterwave');
        $admin = paymentStaff('store-manager');
        Http::fake(['api.flutterwave.com/v3/transactions/288200108/refund' => Http::response(['status' => 'success', 'message' => 'Transaction refund initiated', 'data' => ['id' => 75923, 'status' => 'completed', 'amount_refunded' => 100]])]);

        refundRequest($admin, $payment, ['amount_kobo' => 1_000_000, 'reason' => 'Customer request'])->assertCreated()->assertJsonPath('data.state', 'pending');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.flutterwave.com/v3/transactions/288200108/refund' && $r['amount'] === '10000.00' && $r['comments'] === 'Customer request' && $r->hasHeader('Authorization', 'Bearer '.FLW_SECRET));
    });

    it('enforces the gateway minimum, except for the final remainder', function () {
        $payment = settledPayment('flutterwave');
        $admin = paymentStaff('store-manager');
        Http::fake(['api.flutterwave.com/v3/transactions/*/refund' => Http::response(['status' => 'success', 'data' => ['id' => 1, 'status' => 'completed']])]);

        refundRequest($admin, $payment, ['amount_kobo' => 5_000, 'reason' => 'Tiny refund'])->assertUnprocessable()->assertJsonPath('details.min_kobo', 10_000);
        refundRequest($admin, $payment, ['amount_kobo' => $payment->amount_kobo - 5_000, 'reason' => 'Almost all'], 'second-key-0123456789')->assertCreated();
        // The last 50 naira is below the minimum but is the whole remainder, so it is allowed.
        refundRequest($admin, $payment, ['reason' => 'The remainder'], 'third-key-0123456789')->assertCreated();
    });
});

describe('payments needing review', function () {
    it('lets staff refund money that was received but could not be fulfilled', function () {
        [$order, $token] = payableOrder();
        $payment = initPayment($order, $token);
        Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment, ['requested_amount' => $payment->amount_kobo, 'amount' => $payment->amount_kobo, 'currency' => 'USD'])]);
        verifyPayment($payment, $token)->assertJsonPath('data.state', 'review');
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 5, 'status' => 'pending']])]);

        refundRequest(paymentStaff('store-manager'), $payment->refresh())->assertCreated();

        // The order was never paid, so its own payment status must not claim a refund.
        expect($payment->refresh()->refunded_kobo)->toBe($payment->amount_kobo)->and($order->refresh()->payment_status)->toBe(PaymentStatus::Pending)->and($order->status)->toBe(OrderStatus::PendingPayment);
    });

    it('lists payments for staff, filterable by state, with the right permission', function () {
        settledPayment();
        [$order, $token] = payableOrder();
        $review = initPayment($order, $token);
        $review->forceFill(['state' => PaymentState::Review, 'review_reason' => 'duplicate_payment'])->save();

        $manager = paymentStaff('order-manager');
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/admin/payments')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/admin/payments?state=review')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.review_reason', 'duplicate_payment');
        $this->actingAs(paymentStaff('content-editor'), 'sanctum')->getJson('/api/v1/admin/payments')->assertForbidden();
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/admin/payments?state=bogus')->assertUnprocessable();
    });
});
