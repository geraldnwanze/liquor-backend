<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Orders\OrderLifecycle;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureGateways();
    Http::preventStrayRequests();
});

function settingsPayload(array $business = [], array $commerce = []): array
{
    return [
        'business' => array_merge(['name' => 'Demo Cellars Ltd', 'address' => '1 Demo Street, Lagos', 'phone' => '0803 000 0000', 'email' => 'hello@example.test', 'registration_number' => 'RC 000000', 'receipt_footer' => 'Drink responsibly.'], $business),
        'commerce' => array_merge(['minimum_age' => 18, 'reservation_minutes' => 30, 'max_quantity_per_line' => 12, 'max_units_per_order' => 36], $commerce),
    ];
}

describe('who can change settings', function () {
    it('is for managers with the settings permission only', function () {
        $this->getJson('/api/v1/admin/settings')->assertUnauthorized();
        asStaff(User::factory()->create())->getJson('/api/v1/admin/settings')->assertForbidden();
        asStaff(orderStaff('order-manager'))->getJson('/api/v1/admin/settings')->assertForbidden();
        asStaff(orderStaff('customer-support'))->putJson('/api/v1/admin/settings', settingsPayload())->assertForbidden();
        asStaff(orderStaff('marketing-manager'))->getJson('/api/v1/admin/settings')->assertForbidden();
        asStaff(orderStaff('store-manager'))->getJson('/api/v1/admin/settings')->assertOk();
    });
});

describe('reading settings', function () {
    it('shows the defaults that apply while nothing is saved', function () {
        asStaff(orderStaff('store-manager'))->getJson('/api/v1/admin/settings')->assertOk()
            ->assertJsonPath('data.business.name', null)->assertJsonPath('data.commerce.minimum_age', 18)
            ->assertJsonPath('data.commerce.max_quantity_per_line', 12)->assertJsonPath('data.defaults.business_name', config('app.name'));
    });
});

describe('saving settings', function () {
    it('stores business details and limits, and audits what changed', function () {
        $res = asStaff(orderStaff('store-manager'))->putJson('/api/v1/admin/settings', settingsPayload(commerce: ['max_quantity_per_line' => 6, 'max_units_per_order' => 24]))->assertOk()
            ->assertJsonPath('data.business.name', 'Demo Cellars Ltd')->assertJsonPath('data.commerce.max_quantity_per_line', 6);

        $log = AuditLog::where('action', 'settings.updated')->first();
        expect($res->json('data.commerce.max_units_per_order'))->toBe(24)
            ->and($log->metadata['changed']['commerce.max_quantity_per_line'])->toBe(['from' => 12, 'to' => 6])
            ->and($log->metadata['changed'])->toHaveKey('business.name')->not->toHaveKey('commerce.minimum_age');
    });

    it('puts the business details on receipts, and falls back to the app name when the name is blank', function () {
        $staff = orderStaff('store-manager');
        [$order] = payableOrder();
        app(OrderLifecycle::class)->markPaid($order);

        asStaff($staff)->putJson('/api/v1/admin/settings', settingsPayload())->assertOk();
        asStaff($staff)->getJson("/api/v1/admin/orders/{$order->reference}/receipt")->assertOk()
            ->assertJsonPath('data.business.name', 'Demo Cellars Ltd')->assertJsonPath('data.business.registration_number', 'RC 000000')->assertJsonPath('data.footer', 'Drink responsibly.');

        asStaff($staff)->putJson('/api/v1/admin/settings', settingsPayload(['name' => '', 'receipt_footer' => null]))->assertOk()->assertJsonPath('data.business.name', null);
        asStaff($staff)->getJson("/api/v1/admin/orders/{$order->reference}/receipt")->assertJsonPath('data.business.name', config('app.name'))->assertJsonPath('data.footer', null);
    });

    it('changes the limits the shop actually enforces', function () {
        asStaff(orderStaff('store-manager'))->putJson('/api/v1/admin/settings', settingsPayload(commerce: ['max_quantity_per_line' => 2, 'max_units_per_order' => 4, 'reservation_minutes' => 45]))->assertOk();
        $variant = sellableVariant(['on_hand' => 50]);

        $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.commerce.max_quantity_per_line', 2)->assertJsonPath('data.commerce.reservation_minutes', 45);
        guest()->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 3])->assertUnprocessable()->assertJsonPath('code', 'quantity_limit');
        guest()->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->assertCreated();
    });

    it('strips markup from the business details', function () {
        $res = asStaff(orderStaff('store-manager'))->putJson('/api/v1/admin/settings', settingsPayload(['name' => '<b>Demo</b> Cellars<script>x</script>', 'receipt_footer' => '<i>Thanks</i>']))->assertOk();

        expect($res->json('data.business.name'))->toBe('Demo Cellarsx')->and($res->json('data.business.receipt_footer'))->toBe('Thanks');
    });

    it('refuses values that make no sense', function (array $business, array $commerce, string $field) {
        asStaff(orderStaff('store-manager'))->putJson('/api/v1/admin/settings', settingsPayload($business, $commerce))->assertUnprocessable()->assertJsonValidationErrors($field);
    })->with([
        'a minimum age below 18' => [[], ['minimum_age' => 17], 'commerce.minimum_age'],
        'a silly minimum age' => [[], ['minimum_age' => 150], 'commerce.minimum_age'],
        'a reservation under five minutes' => [[], ['reservation_minutes' => 1], 'commerce.reservation_minutes'],
        'zero items per line' => [[], ['max_quantity_per_line' => 0], 'commerce.max_quantity_per_line'],
        'an order limit below the line limit' => [[], ['max_quantity_per_line' => 12, 'max_units_per_order' => 6], 'commerce.max_units_per_order'],
        'text where a number belongs' => [[], ['max_units_per_order' => 'lots'], 'commerce.max_units_per_order'],
        'a bad email' => [['email' => 'not-an-email'], [], 'business.email'],
        'a name that is too long' => [['name' => str_repeat('x', 121)], [], 'business.name'],
    ]);

    it('rejects a request with the groups missing, and leaves everything unchanged', function () {
        asStaff(orderStaff('store-manager'))->putJson('/api/v1/admin/settings', ['business' => ['name' => 'Only this']])->assertUnprocessable()->assertJsonValidationErrors('commerce');
        expect(AuditLog::where('action', 'settings.updated')->count())->toBe(0);
    });
});
