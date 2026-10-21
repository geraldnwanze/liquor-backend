<?php

use App\Models\Address;
use App\Models\AuditLog;
use App\Models\CustomerNote;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderLifecycle;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureGateways();
    Http::preventStrayRequests();
});

/** Gives an order to a customer account. */
function orderFor(User $customer, bool $paid = true): Order
{
    [$order] = payableOrder();
    $order->forceFill(['user_id' => $customer->id])->save();
    if ($paid) {
        app(OrderLifecycle::class)->markPaid($order);
    }

    return $order->refresh();
}

describe('who can look at customers', function () {
    it('is for customer support and managers only', function () {
        $customer = User::factory()->create();

        $this->getJson('/api/v1/admin/customers')->assertUnauthorized();
        asStaff($customer)->getJson('/api/v1/admin/customers')->assertForbidden();
        asStaff(orderStaff('order-manager'))->getJson('/api/v1/admin/customers')->assertForbidden();
        asStaff(orderStaff('marketing-manager'))->getJson("/api/v1/admin/customers/{$customer->id}")->assertForbidden();
        asStaff(orderStaff('product-manager'))->postJson("/api/v1/admin/customers/{$customer->id}/notes", ['body' => 'hello'])->assertForbidden();
        asStaff(orderStaff('customer-support'))->getJson('/api/v1/admin/customers')->assertOk();
        asStaff(orderStaff('store-manager'))->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();
    });

    it('never lists or opens staff accounts, and never returns secrets', function () {
        $support = orderStaff('customer-support');
        $customer = User::factory()->create(['name' => 'Ada Customer']);

        $list = asStaff($support)->getJson('/api/v1/admin/customers')->assertOk();
        expect(collect($list->json('data'))->pluck('id')->all())->toBe([$customer->id]);

        asStaff($support)->getJson("/api/v1/admin/customers/{$support->id}")->assertNotFound();
        asStaff($support)->postJson("/api/v1/admin/customers/{$support->id}/notes", ['body' => 'nope'])->assertNotFound();

        $json = asStaff($support)->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk()->getContent();
        expect($json)->not->toContain('password')->not->toContain('remember_token');
    });
});

describe('listing customers', function () {
    it('shows paid orders and spend, ignoring unpaid and cancelled ones', function () {
        $support = orderStaff('customer-support');
        $ada = User::factory()->create(['name' => 'Ada Obi']);
        $paid = orderFor($ada);
        orderFor($ada, paid: false); // awaiting payment
        $none = User::factory()->create(['name' => 'Bola New']);

        $rows = collect(asStaff($support)->getJson('/api/v1/admin/customers')->assertOk()->json('data'))->keyBy('id');

        expect($rows[$ada->id]['paid_orders'])->toBe(1)->and($rows[$ada->id]['spent']['kobo'])->toBe($paid->total_kobo)
            ->and($rows[$none->id]['paid_orders'])->toBe(0)->and($rows[$none->id]['spent']['kobo'])->toBe(0);
    });

    it('searches by name, email and phone', function () {
        $support = orderStaff('customer-support');
        User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada@example.com', 'phone' => '+2348031234567']);
        User::factory()->create(['name' => 'Chidi Eze', 'email' => 'chidi@example.com', 'phone' => '+2348099990000']);

        foreach (['obi', 'chidi@exam', '0803 123 4567'] as $term) {
            asStaff($support)->getJson('/api/v1/admin/customers?'.http_build_query(['q' => $term]))->assertOk()->assertJsonCount(1, 'data');
        }
        asStaff($support)->getJson('/api/v1/admin/customers?q=nobody')->assertOk()->assertJsonCount(0, 'data');
    });

    it('treats search characters literally', function () {
        User::factory()->create(['name' => 'Ada Obi']);

        asStaff(orderStaff('customer-support'))->getJson('/api/v1/admin/customers?q=%25')->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('one customer', function () {
    it('shows details, addresses, recent orders and spend', function () {
        $ada = User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada@example.com']);
        Address::factory()->create(['user_id' => $ada->id, 'city' => 'Ikeja', 'is_default' => true]);
        $order = orderFor($ada);
        orderFor(User::factory()->create()); // someone else's

        asStaff(orderStaff('customer-support'))->getJson("/api/v1/admin/customers/{$ada->id}")->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com')->assertJsonPath('data.stats.orders', 1)->assertJsonPath('data.stats.paid_orders', 1)
            ->assertJsonPath('data.stats.spent.kobo', $order->total_kobo)->assertJsonPath('data.addresses.0.city', 'Ikeja')
            ->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.reference', $order->reference);
    });

    it('404s an unknown customer', function () {
        asStaff(orderStaff('customer-support'))->getJson('/api/v1/admin/customers/999999')->assertNotFound();
    });
});

describe('support notes', function () {
    it('adds a note as plain text, records who wrote it and audits it', function () {
        $support = orderStaff('customer-support');
        $customer = User::factory()->create();

        $res = asStaff($support)->postJson("/api/v1/admin/customers/{$customer->id}/notes", ['body' => '  <b>Prefers</b> evening delivery <script>x</script> '])->assertCreated();

        expect($res->json('data.0.body'))->toBe('Prefers evening delivery x')->and($res->json('data.0.by'))->toBe($support->name);
        expect(AuditLog::where('action', 'customer.note_added')->count())->toBe(1);
        asStaff($support)->getJson("/api/v1/admin/customers/{$customer->id}")->assertJsonPath('data.notes.0.body', 'Prefers evening delivery x');
    });

    it('validates the note', function (array $body) {
        $customer = User::factory()->create();

        asStaff(orderStaff('customer-support'))->postJson("/api/v1/admin/customers/{$customer->id}/notes", $body)->assertUnprocessable()->assertJsonValidationErrors('body');
    })->with([
        'missing' => [[]],
        'too short' => [['body' => 'a']],
        'too long' => [['body' => str_repeat('x', 1001)]],
        'only markup' => [['body' => '<p></p>']],
    ]);

    it('lists newest first, never shows notes to the customer, and can delete one', function () {
        $support = orderStaff('customer-support');
        $customer = User::factory()->create();
        $first = CustomerNote::create(['customer_id' => $customer->id, 'author_id' => $support->id, 'body' => 'first note']);
        CustomerNote::create(['customer_id' => $customer->id, 'author_id' => $support->id, 'body' => 'second note']);

        asStaff($support)->getJson("/api/v1/admin/customers/{$customer->id}")->assertJsonPath('data.notes.0.body', 'second note')->assertJsonPath('data.notes.1.body', 'first note');
        asStaff($customer)->getJson('/api/v1/me')->assertOk();
        expect(asStaff($customer)->getJson('/api/v1/me')->getContent())->not->toContain('second note');

        asStaff($support)->deleteJson("/api/v1/admin/customers/{$customer->id}/notes/{$first->id}")->assertOk()->assertJsonCount(1, 'data');
        expect(AuditLog::where('action', 'customer.note_deleted')->count())->toBe(1);
    });

    it('will not delete a note through the wrong customer', function () {
        $support = orderStaff('customer-support');
        $a = User::factory()->create();
        $b = User::factory()->create();
        $note = CustomerNote::create(['customer_id' => $a->id, 'author_id' => $support->id, 'body' => 'about A']);

        asStaff($support)->deleteJson("/api/v1/admin/customers/{$b->id}/notes/{$note->id}")->assertNotFound();
        expect(CustomerNote::count())->toBe(1);
    });
});
