<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureGateways();
    Http::preventStrayRequests();
});

function agedPayment(int $minutes, string $gateway = 'paystack'): array
{
    [$order, $token] = payableOrder();
    $payment = initPayment($order, $token, $gateway);
    $payment->forceFill(['created_at' => now()->subMinutes($minutes)])->save();

    return [$payment, $order];
}

it('finds payments the browser and webhook missed, and settles them', function () {
    [$payment, $order] = agedPayment(10);
    Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment)]);

    $this->artisan('payments:reconcile')->expectsOutput('Checked 1 payment(s): 1 newly paid, 0 abandoned.')->assertSuccessful();

    expect($payment->refresh()->state)->toBe(PaymentState::Succeeded)->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('leaves very recent payments alone (the customer is probably still paying)', function () {
    [$payment] = agedPayment(1);
    Http::fake();

    $this->artisan('payments:reconcile')->expectsOutput('Checked 0 payment(s): 0 newly paid, 0 abandoned.');

    Http::assertNothingSent();
    expect($payment->refresh()->state)->toBe(PaymentState::Pending);
});

it('does not touch payments that are already settled', function () {
    [$payment, $order] = agedPayment(10);
    Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment)]);
    $this->artisan('payments:reconcile');
    Http::assertSentCount(1);

    $this->artisan('payments:reconcile')->expectsOutput('Checked 0 payment(s): 0 newly paid, 0 abandoned.');

    Http::assertSentCount(1);
});

it('gives up on payments that stay pending for a day', function () {
    [$payment, $order] = agedPayment(60 * 25);
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => false, 'message' => 'Transaction reference not found'], 400)]);

    $this->artisan('payments:reconcile')->expectsOutput('Checked 1 payment(s): 0 newly paid, 1 abandoned.');

    expect($payment->refresh()->state)->toBe(PaymentState::Abandoned)->and($order->refresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('still catches a late success on a payment it had written off', function () {
    [$payment, $order] = agedPayment(60 * 30);
    $payment->forceFill(['state' => PaymentState::Abandoned])->save();
    Http::fake(['api.paystack.co/transaction/verify/*' => paystackVerifyResponse($payment)]);

    $this->artisan('payments:reconcile');

    expect($payment->refresh()->state)->toBe(PaymentState::Succeeded);
});

it('keeps going when one gateway call fails', function () {
    [$first] = agedPayment(10);
    [$second, $secondOrder] = agedPayment(10);
    $failing = $first->reference;
    Http::fake(['api.paystack.co/transaction/verify/*' => fn ($r) => str_contains($r->url(), $failing) ? Http::response(['message' => 'down'], 503) : paystackVerifyResponse($second)]);

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($first->refresh()->state)->toBe(PaymentState::Pending)->and($second->refresh()->state)->toBe(PaymentState::Succeeded);
    expect($first->attempts()->where('type', 'reconcile')->where('outcome', 'error')->exists())->toBeTrue();
});

it('is scheduled to run regularly', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('payments:reconcile')->assertSuccessful();
});
