<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Laravel's own plumbing (queue, cache, sessions, migrations) keeps its internal keys; everything else is a UUID. */
const FRAMEWORK_TABLES = ['migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens'];

it('uses a UUID primary key on every application table', function () {
    $tables = collect(Schema::getTables())->pluck('name')->reject(fn ($t) => in_array($t, FRAMEWORK_TABLES, true) || str_starts_with($t, 'sqlite_'));

    expect($tables)->not->toBeEmpty();
    foreach ($tables as $table) {
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true)['columns'] ?? [];
        foreach ($primary as $column) {
            // Pivot tables are keyed by two foreign keys; both must be UUIDs too.
            expect($columns[$column]['type'])->toMatch('/char|uuid|varchar/i', "{$table}.{$column} should hold a UUID");
        }
        foreach ($columns->keys()->filter(fn ($c) => $c === 'id' || str_ends_with($c, '_id')) as $column) {
            expect($columns[$column]['type'])->not->toMatch('/int/i', "{$table}.{$column} should not be an integer");
        }
    }
});

it('creates ordered UUIDs for records and returns them as strings in the API', function () {
    $product = Product::factory()->create();
    $user = User::factory()->create();

    expect(Str::isUuid($product->id))->toBeTrue()->and(Str::isUuid($user->id))->toBeTrue();

    $staff = orderStaff('product-manager');
    $res = asStaff($staff)->getJson('/api/v1/admin/products')->assertOk();
    expect($res->json('data.0.id'))->toBeString()->and(Str::isUuid($res->json('data.0.id')))->toBeTrue();
});

it('sorts newer records after older ones', function () {
    $first = Product::factory()->create();
    $second = Product::factory()->create();

    expect($second->id > $first->id)->toBeTrue();
});

it('looks records up by UUID and treats a numeric or malformed id as not found', function () {
    $product = Product::factory()->draft()->create();
    $staff = orderStaff('product-manager');

    asStaff($staff)->getJson("/api/v1/admin/products/{$product->id}")->assertOk();
    asStaff($staff)->getJson('/api/v1/admin/products/1')->assertNotFound();
    asStaff($staff)->getJson('/api/v1/admin/products/not-a-uuid')->assertNotFound();
    asStaff($staff)->getJson('/api/v1/admin/products/'.Str::uuid())->assertNotFound();
    asStaff(orderStaff('customer-support'))->getJson('/api/v1/admin/customers/1')->assertNotFound();
});

it('rejects numeric ids where a UUID is expected in a request body', function () {
    sellableVariant();
    guest()->postJson('/api/v1/cart/items', ['variant_id' => 1, 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('variant_id');
});

it('issues sign-in tokens whose own id is a UUID', function () {
    $staff = orderStaff('order-manager');
    $staff->forceFill(['password' => 'Staff-Pass-12345'])->save();

    $token = guest()->postJson('/api/v1/auth/staff/login', ['email' => $staff->email, 'password' => 'Staff-Pass-12345'])->assertOk()->json('data.token');

    [$id] = explode('|', $token, 2);
    expect(Str::isUuid($id))->toBeTrue();
    guest()->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $staff->id);
});
