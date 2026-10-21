<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Commerce test helpers
|--------------------------------------------------------------------------
*/

use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/** A live, purchasable variant: active product, 10 in stock, NGN 25,000. */
function sellableVariant(array $variant = [], array $product = []): ProductVariant
{
    $p = Product::factory()->create($product);

    return ProductVariant::factory()->for($p)->create($variant);
}

function ikejaZone(array $over = []): DeliveryZone
{
    return DeliveryZone::factory()->create(['name' => 'Lagos Mainland (demo)', 'state' => 'Lagos', 'city' => 'Ikeja', 'fee_kobo' => 150_000, ...$over]);
}

/**
 * A request as a guest holding this cart token (or none). Laravel's withHeader() persists for the rest of the
 * test, so this always resets the header first to stop tokens leaking between steps.
 */
function guest(?string $token = null): TestCase
{
    app('auth')->forgetGuards(); // actingAs() would otherwise stay signed in for later requests
    $test = test()->withoutHeader('X-Cart-Token');

    return $token ? $test->withHeader('X-Cart-Token', $token) : $test;
}

/** Adds to a guest cart through the API and returns that cart's token. */
function addToCart(ProductVariant $variant, int $quantity = 1, ?string $token = null): string
{
    $response = guest($token)->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => $quantity])->assertCreated();

    return $response->json('data.token') ?? $token;
}

/** @return array<string, mixed> */
function checkoutPayload(array $over = []): array
{
    return [
        'name' => 'Ada Obi', 'email' => 'ada@example.com', 'phone' => '0803 123 4567',
        'state' => 'Lagos', 'city' => 'Ikeja', 'address' => '12 Allen Avenue', 'age_confirmed' => true, ...$over,
    ];
}

function checkout(string $cartToken, array $payload = [], string $key = 'idem-key-0123456789'): TestResponse
{
    return guest($cartToken)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/checkout', checkoutPayload($payload));
}

/*
|--------------------------------------------------------------------------
| Payment test helpers
|--------------------------------------------------------------------------
*/

use App\Models\Payment;
use GuzzleHttp\Promise\PromiseInterface;

const PAYSTACK_SECRET = 'sk_test_paystack_secret';
const FLW_SECRET = 'FLWSECK_TEST-flutterwave-secret';
const FLW_HASH = 'flw-webhook-secret-hash';

function configureGateways(): void
{
    config([
        'payments.gateways.paystack.secret_key' => PAYSTACK_SECRET,
        'payments.gateways.flutterwave.secret_key' => FLW_SECRET,
        'payments.gateways.flutterwave.secret_hash' => FLW_HASH,
        'payments.gateways.fake.enabled' => false,
        'app.frontend_url' => 'https://shop.test',
    ]);
}

/** A real pending-payment order created through the public checkout API. @return array{0: Order, 1: string} */
function payableOrder(int $quantity = 1, array $variant = []): array
{
    DeliveryZone::exists() || ikejaZone();
    $token = addToCart(sellableVariant($variant), $quantity);
    $reference = checkout($token)->assertCreated()->json('data.reference');

    return [Order::firstWhere('reference', $reference), $token];
}

function initPayment(Order $order, string $token, string $gateway = 'paystack'): Payment
{
    $fake = $gateway === 'paystack'
        ? ['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'message' => 'Authorization URL created', 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123', 'access_code' => 'abc123', 'reference' => 'x']])]
        : ['api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'message' => 'Hosted Link', 'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/flwlnk-abc']])];
    Http::fake($fake);

    $reference = guest($token)->postJson("/api/v1/orders/{$order->reference}/payments", ['gateway' => $gateway])->assertCreated()->json('data.reference');
    Http::preventStrayRequests();

    return Payment::firstWhere('reference', $reference);
}

function paystackVerifyResponse(Payment $p, array $over = []): Illuminate\Http\Client\PromiseInterface|PromiseInterface
{
    return Http::response(['status' => true, 'message' => 'Verification successful', 'data' => array_merge([
        'id' => 4099260516, 'status' => 'success', 'reference' => $p->reference, 'amount' => $p->amount_kobo,
        'requested_amount' => $p->amount_kobo, 'currency' => 'NGN', 'channel' => 'card', 'gateway_response' => 'Successful',
    ], $over)]);
}

function flutterwaveVerifyResponse(Payment $p, array $over = []): Illuminate\Http\Client\PromiseInterface|PromiseInterface
{
    return Http::response(['status' => 'success', 'message' => 'Transaction fetched successfully', 'data' => array_merge([
        'id' => 288200108, 'tx_ref' => $p->reference, 'amount' => $p->amount_kobo / 100, 'currency' => 'NGN',
        'status' => 'successful', 'payment_type' => 'card', 'processor_response' => 'Approved',
    ], $over)]);
}

function verifyPayment(Payment $payment, string $token): TestResponse
{
    return guest($token)->postJson("/api/v1/payments/{$payment->reference}/verify");
}

/** Sends a webhook exactly as Paystack would: raw JSON body signed with HMAC-SHA512. */
function paystackWebhook(array $payload, ?string $secret = PAYSTACK_SECRET, ?string $rawOverride = null): TestResponse
{
    $raw = $rawOverride ?? json_encode($payload, JSON_PRETTY_PRINT);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($secret !== null) {
        $headers['HTTP_X_PAYSTACK_SIGNATURE'] = hash_hmac('sha512', $raw, $secret);
    }

    return test()->call('POST', '/api/v1/webhooks/paystack', [], [], [], $headers, $raw);
}

function flutterwaveWebhook(array $payload, ?string $hash = FLW_HASH): TestResponse
{
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($hash !== null) {
        $headers['HTTP_VERIF_HASH'] = $hash;
    }

    return test()->call('POST', '/api/v1/webhooks/flutterwave', [], [], [], $headers, json_encode($payload));
}

/**
 * Starts a fresh request log. Http::fake() keeps earlier stubs (the first match wins) and restarts recording, and a
 * no-argument fake() installs a catch-all that would beat later stubs, so this registers an unrelated stub instead.
 */
function resetHttpRecording(): void
{
    Http::fake(['reset-recording.invalid/*' => Http::response([])]);
}

/*
|--------------------------------------------------------------------------
| Staff test helpers
|--------------------------------------------------------------------------
*/

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/** A staff member holding one of the seeded roles (e.g. "order-manager"). */
function orderStaff(string $role): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create(['name' => ucfirst($role).' Person']);
    $user->roles()->attach(Role::where('slug', $role)->first());

    return $user;
}

/** Requests as this signed-in user, clearing any earlier acting-as so users never leak between steps. */
function asStaff(User $user): TestCase
{
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'sanctum');
}
