<?php

use App\Exceptions\CommerceException;
use App\Models\Product;
use App\Services\Catalog\ProductImageStore;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'filesystems.product_images_disk' => 'cloudinary',
        'filesystems.disks.cloudinary.cloud_name' => 'demo',
        'filesystems.disks.cloudinary.api_key' => 'key123',
        'filesystems.disks.cloudinary.api_secret' => 'secret456',
        'filesystems.disks.cloudinary.url' => null,
    ]);
    Storage::forgetDisk('cloudinary');
});

/** Form fields of a multipart request, by name. */
function cloudinaryFields(Request $request): array
{
    return collect($request->data())->mapWithKeys(fn ($part) => [$part['name'] => $part['contents']])->all();
}

it('uploads a signed, re-encoded image and serves it from Cloudinary', function () {
    Http::fake(['api.cloudinary.com/*' => Http::response(['public_id' => 'ok'])]);
    $product = Product::factory()->create();

    $image = app(ProductImageStore::class)->store(UploadedFile::fake()->image('a.jpg', 40, 30), $product, 'Bottle', 0);

    Http::assertSent(function (Request $request) use ($image) {
        $f = cloudinaryFields($request);
        $publicId = preg_replace('/\.webp$/', '', $image->getRawOriginal('path'));

        return $request->url() === 'https://api.cloudinary.com/v1_1/demo/image/upload'
            && $f['public_id'] === $publicId
            && $f['api_key'] === 'key123'
            && str_starts_with($f['file'], 'data:image/webp;base64,')
            && $f['signature'] === sha1("overwrite=true&public_id={$publicId}&timestamp={$f['timestamp']}secret456");
    });
    expect($image->url)->toBe('https://res.cloudinary.com/demo/image/upload/'.$image->getRawOriginal('path'));
});

it('refuses to record an image when Cloudinary rejects the upload', function () {
    Http::fake(['api.cloudinary.com/*' => Http::response(['error' => ['message' => 'Invalid Signature']], 401)]);
    $product = Product::factory()->create();

    expect(fn () => app(ProductImageStore::class)->store(UploadedFile::fake()->image('a.jpg'), $product, null, 0))
        ->toThrow(CommerceException::class);
    expect($product->images()->count())->toBe(0);
});

it('deletes the asset by public id and treats a missing asset as already gone', function () {
    Http::fake(['api.cloudinary.com/*' => Http::sequence()->push(['public_id' => 'ok'])->push(['result' => 'ok'])]);
    $product = Product::factory()->create();
    $store = app(ProductImageStore::class);
    $image = $store->store(UploadedFile::fake()->image('a.jpg'), $product, null, 0);

    $store->deletePath($image->getRawOriginal('path'));

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/image/destroy')
        && cloudinaryFields($r)['public_id'] === preg_replace('/\.webp$/', '', $image->getRawOriginal('path')));
});

it('copies by handing Cloudinary the source address', function () {
    Http::fake(['api.cloudinary.com/*' => Http::response(['public_id' => 'ok'])]);
    $from = Product::factory()->create();
    $to = Product::factory()->create();
    $store = app(ProductImageStore::class);
    $image = $store->store(UploadedFile::fake()->image('a.jpg'), $from, null, 0);

    $copy = $store->copy($image, $to);

    Http::assertSent(fn (Request $r) => (cloudinaryFields($r)['file'] ?? null) === $image->url);
    expect($copy->getRawOriginal('path'))->toStartWith("products/{$to->id}/");
});

it('honours a custom delivery base such as a CDN or transformation', function () {
    config(['filesystems.disks.cloudinary.url' => 'https://img.example.com/image/upload/f_auto,q_auto/']);
    Storage::forgetDisk('cloudinary');

    expect(Storage::disk('cloudinary')->url('products/1/a.webp'))->toBe('https://img.example.com/image/upload/f_auto,q_auto/products/1/a.webp');
});
