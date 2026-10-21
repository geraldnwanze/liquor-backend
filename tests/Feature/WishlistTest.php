<?php

use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;

it('saves, lists and removes wishlist products, idempotently', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    sellableVariant([], []);

    asStaff($user)->putJson("/api/v1/me/wishlist/{$product->slug}")->assertCreated();
    asStaff($user)->putJson("/api/v1/me/wishlist/{$product->slug}")->assertCreated();
    expect(WishlistItem::count())->toBe(1);

    asStaff($user)->getJson('/api/v1/me/wishlist/slugs')->assertExactJson(['data' => [$product->slug]]);
    asStaff($user)->getJson('/api/v1/me/wishlist')->assertOk()->assertJsonPath('data.0.slug', $product->slug);

    asStaff($user)->deleteJson("/api/v1/me/wishlist/{$product->slug}")->assertNoContent();
    asStaff($user)->deleteJson("/api/v1/me/wishlist/{$product->slug}")->assertNoContent();
    expect(WishlistItem::count())->toBe(0);
});

it('keeps each customer\'s wishlist private', function () {
    $product = Product::factory()->create();
    $other = User::factory()->create();
    WishlistItem::create(['user_id' => $other->id, 'product_id' => $product->id]);

    asStaff(User::factory()->create())->getJson('/api/v1/me/wishlist/slugs')->assertExactJson(['data' => []]);
    asStaff(User::factory()->create())->deleteJson("/api/v1/me/wishlist/{$product->slug}")->assertNoContent();
    expect(WishlistItem::count())->toBe(1);
});

it('needs sign-in and refuses unpublished products', function () {
    $draft = Product::factory()->draft()->create();

    guest()->putJson("/api/v1/me/wishlist/{$draft->slug}")->assertUnauthorized();
    guest()->getJson('/api/v1/me/wishlist')->assertUnauthorized();
    asStaff(User::factory()->create())->putJson("/api/v1/me/wishlist/{$draft->slug}")->assertNotFound();
});
