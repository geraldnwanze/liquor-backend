<?php

use App\Enums\OrderFulfilment;
use App\Enums\OrderStatus;
use App\Models\AccountDeletionRequest;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CustomerNote;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterDelivery;
use App\Models\NewsletterSubscriber;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Models\WishlistItem;
use App\Notifications\AccountDeletionNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => Storage::fake('public'));

function leaver(): User
{
    return User::factory()->create(['name' => 'Ada Obi', 'email' => 'ada@example.com', 'phone' => '+2348031234567', 'password' => 'Customer-Pass-12345', 'date_of_birth' => '1990-01-01']);
}

function askToLeave(User $user, string $password = 'Customer-Pass-12345', array $extra = []): TestResponse
{
    return asStaff($user)->postJson('/api/v1/me/deletion-request', ['password' => $password] + $extra);
}

it('lets a customer ask to delete their account, only with their password, and only once at a time', function () {
    $user = leaver();

    askToLeave($user, 'wrong-password-1')->assertUnprocessable()->assertJsonValidationErrors('password');
    expect(AccountDeletionRequest::count())->toBe(0);

    $first = askToLeave($user, extra: ['reason' => '<b>Moving away</b>'])->assertCreated()->json('data.id');
    askToLeave($user)->assertOk()->assertJsonPath('data.id', $first);
    expect(AccountDeletionRequest::count())->toBe(1)->and(AccountDeletionRequest::first()->reason)->toBe('Moving away');
    asStaff($user)->getJson('/api/v1/me/deletion-request')->assertJsonPath('data.status', 'pending');
    guest()->postJson('/api/v1/me/deletion-request', ['password' => 'x'])->assertUnauthorized();
});

it('lets a customer change their mind, and refuses staff accounts', function () {
    $user = leaver();
    askToLeave($user)->assertCreated();

    asStaff($user)->deleteJson('/api/v1/me/deletion-request')->assertNoContent();
    asStaff($user)->getJson('/api/v1/me/deletion-request')->assertJsonPath('data', null);
    expect(AccountDeletionRequest::first()->status)->toBe('cancelled');

    $staff = orderStaff('order-manager');
    $staff->forceFill(['password' => 'Staff-Pass-12345'])->save();
    asStaff($staff)->postJson('/api/v1/me/deletion-request', ['password' => 'Staff-Pass-12345'])->assertForbidden()->assertJsonPath('code', 'staff_account');
});

it('erases personal data but keeps the account row and the orders', function () {
    Notification::fake();
    configureGateways();
    ikejaZone();
    $user = leaver();
    $address = Address::factory()->for($user)->create(['address_line' => '12 Allen Avenue']);
    $note = CustomerNote::create(['customer_id' => $user->id, 'author_id' => $user->id, 'body' => 'Calls from 0803 123 4567']);
    $product = Product::factory()->create();
    $review = Review::factory()->for($product)->create(['user_id' => $user->id, 'body' => 'My phone is 0803 123 4567']);
    $photo = $review->images()->create(['path' => 'reviews/x/photo.webp', 'sort_order' => 0]);
    Storage::disk('public')->put('reviews/x/photo.webp', 'data');
    WishlistItem::create(['user_id' => $user->id, 'product_id' => $product->id]);
    Cart::create(['user_id' => $user->id, 'token' => (string) Str::uuid()]);
    $subscriber = NewsletterSubscriber::factory()->confirmed()->create(['email' => 'ada@example.com']);
    $delivery = NewsletterDelivery::create(['campaign_id' => NewsletterCampaign::factory()->create()->id, 'subscriber_id' => $subscriber->id, 'email' => 'ada@example.com', 'status' => 'sent']);
    [$order] = payableOrder();
    $order->forceFill(['user_id' => $user->id, 'status' => OrderStatus::Paid, 'fulfilment_status' => OrderFulfilment::Delivered, 'paid_at' => now()])->save();
    $orderName = $order->customer_name;
    $orderEmail = $order->email;
    $tokenBefore = $user->createToken('api')->plainTextToken;

    $request = askToLeave($user)->assertCreated()->json('data.id');
    asStaff(orderStaff('customer-support'))->postJson("/api/v1/admin/deletion-requests/{$request}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

    $user->refresh();
    expect($user->name)->toBe('Deleted customer')->and($user->email)->toBe("deleted-{$user->id}@deleted.invalid")->and($user->phone)->toBeNull()->and($user->date_of_birth)->toBeNull()
        ->and($user->is_active)->toBeFalse()->and($user->anonymized_at)->not->toBeNull()->and($user->tokens()->count())->toBe(0);
    guest()->withToken($tokenBefore)->getJson('/api/v1/me')->assertUnauthorized();
    guest()->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Customer-Pass-12345'])->assertUnprocessable();

    $this->assertSoftDeleted('addresses', ['id' => $address->id]);
    $gone = Address::withTrashed()->find($address->id);
    expect([$gone->recipient_name, $gone->phone, $gone->address_line, $gone->city, $gone->state])->each->toBe('[removed]')
        ->and($gone->instructions)->toBeNull()
        ->and(Hash::check('Customer-Pass-12345', $user->password))->toBeFalse()
        ->and(CustomerNote::withTrashed()->find($note->id)->body)->toBe('[removed]')
        ->and(Review::withTrashed()->find($review->id)->body)->toBe('[removed]')
        ->and(WishlistItem::where('user_id', $user->id)->count())->toBe(0)
        ->and(Cart::where('user_id', $user->id)->count())->toBe(0)
        ->and(NewsletterSubscriber::where('email', 'ada@example.com')->count())->toBe(0)
        ->and($delivery->refresh()->email)->toBe('[erased]');
    Storage::disk('public')->assertMissing('reviews/x/photo.webp');
    expect($photo->refresh()->path)->toBe('');

    // Orders are records the business must keep: they stay exactly as they were.
    expect($order->refresh()->customer_name)->toBe($orderName)->and($order->email)->toBe($orderEmail)->and($order->user_id)->toBe($user->id);

    Notification::assertSentOnDemand(AccountDeletionNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'ada@example.com' && $n->outcome === 'completed');
    expect(AuditLog::where('action', 'customer.erased')->where('subject_id', $user->id)->count())->toBe(1);
});

it('waits for open orders to finish before erasing', function () {
    configureGateways();
    ikejaZone();
    $user = leaver();
    [$order] = payableOrder();
    $order->forceFill(['user_id' => $user->id])->save(); // unpaid
    $request = askToLeave($user)->assertCreated()->json('data.id');
    $support = orderStaff('customer-support');

    asStaff($support)->postJson("/api/v1/admin/deletion-requests/{$request}/complete")->assertConflict()->assertJsonPath('code', 'open_orders');
    expect($user->refresh()->anonymized_at)->toBeNull();
    asStaff($support)->getJson('/api/v1/admin/deletion-requests')->assertJsonPath('data.0.has_open_orders', true);

    $order->forceFill(['status' => OrderStatus::Paid, 'fulfilment_status' => OrderFulfilment::Processing, 'paid_at' => now()])->save();
    asStaff($support)->postJson("/api/v1/admin/deletion-requests/{$request}/complete")->assertConflict();

    $order->forceFill(['fulfilment_status' => OrderFulfilment::Delivered])->save();
    asStaff($support)->postJson("/api/v1/admin/deletion-requests/{$request}/complete")->assertOk();
    expect($user->refresh()->anonymized_at)->not->toBeNull();

    $order->forceFill(['status' => OrderStatus::Cancelled])->save();
});

it('lets staff decline with a reason the customer is told, and never reprocess a closed request', function () {
    Notification::fake();
    $user = leaver();
    $request = askToLeave($user)->assertCreated()->json('data.id');
    $support = orderStaff('customer-support');

    asStaff($support)->postJson("/api/v1/admin/deletion-requests/{$request}/reject", [])->assertJsonValidationErrors('note');
    asStaff($support)->postJson("/api/v1/admin/deletion-requests/{$request}/reject", ['note' => 'We are investigating a dispute'])->assertOk();

    expect(AccountDeletionRequest::first())->status->toBe('rejected')->staff_note->toBe('We are investigating a dispute')->and($user->refresh()->anonymized_at)->toBeNull();
    Notification::assertSentOnDemand(AccountDeletionNotification::class, fn ($n) => $n->outcome === 'rejected' && $n->note === 'We are investigating a dispute');
    asStaff($support)->postJson("/api/v1/admin/deletion-requests/{$request}/complete")->assertConflict()->assertJsonPath('code', 'request_closed');
    asStaff($support)->postJson("/api/v1/admin/deletion-requests/{$request}/reject", ['note' => 'Again please'])->assertConflict();
    asStaff($support)->getJson('/api/v1/admin/deletion-requests?status=rejected')->assertJsonCount(1, 'data');
});

it('limits the review queue to customer staff and hides erased customers', function () {
    $user = leaver();
    $request = askToLeave($user)->assertCreated()->json('data.id');

    foreach (['order-manager', 'content-editor', 'marketing-manager'] as $role) {
        asStaff(orderStaff($role))->getJson('/api/v1/admin/deletion-requests')->assertForbidden();
        asStaff(orderStaff($role))->postJson("/api/v1/admin/deletion-requests/{$request}/complete")->assertForbidden();
    }
    asStaff(User::factory()->create())->getJson('/api/v1/admin/deletion-requests')->assertForbidden();
    guest()->getJson('/api/v1/admin/deletion-requests')->assertUnauthorized();

    $support = orderStaff('customer-support');
    asStaff($support)->postJson("/api/v1/admin/deletion-requests/{$request}/complete")->assertOk();
    asStaff($support)->getJson('/api/v1/admin/deletion-requests?status=completed')->assertJsonPath('data.0.customer.name', 'Deleted customer')->assertJsonPath('data.0.customer.email', null);
    asStaff($support)->postJson("/api/v1/admin/customers/{$user->id}/suspend")->assertConflict();
    asStaff($support)->getJson("/api/v1/admin/customers/{$user->id}")->assertJsonPath('data.erased', true);
});
