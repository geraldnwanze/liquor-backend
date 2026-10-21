<?php

use App\Models\Brand;
use App\Models\Category;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => Storage::fake('public'));

dataset('owners', [
    'category image' => [fn () => [Category::factory()->create(['image_url' => 'https://cdn.test/old.jpg']), 'categories', 'image', 'image_path', 'image_url']],
    'brand logo' => [fn () => [Brand::factory()->create(['logo_url' => 'https://cdn.test/old.png']), 'brands', 'logo', 'logo_path', 'logo_url']],
]);

function pictureUpload(string $url, UploadedFile $file, string $role = 'product-manager'): TestResponse
{
    return asStaff(orderStaff($role))->post($url, ['file' => $file], ['Accept' => 'application/json']);
}

it('stores an upload as WebP, prefers it over a pasted address, and shows it in the admin and public API', function (Closure $setup) {
    [$record, $segment, $field, $pathColumn, $urlColumn] = $setup();

    $res = pictureUpload("/api/v1/admin/{$segment}/{$record->id}/{$field}", UploadedFile::fake()->image('p.png', 1600, 800))->assertOk();

    $path = $record->refresh()->getRawOriginal($pathColumn);
    Storage::disk('public')->assertExists($path);
    expect(getimagesizefromstring(Storage::disk('public')->get($path))['mime'])->toBe('image/webp')
        ->and($res->json("data.{$urlColumn}"))->toBe($record->{$urlColumn})
        ->and($record->{$urlColumn})->not->toContain('cdn.test');
    asStaff(orderStaff('product-manager'))->getJson("/api/v1/admin/{$segment}/{$record->id}")->assertJsonPath("data.{$urlColumn}", $record->{$urlColumn});
})->with('owners');

it('replaces the file, deleting the old one, and removes both file and address on remove', function (Closure $setup) {
    [$record, $segment, $field, $pathColumn, $urlColumn] = $setup();
    $url = "/api/v1/admin/{$segment}/{$record->id}/{$field}";

    pictureUpload($url, UploadedFile::fake()->image('a.png', 400, 400))->assertOk();
    $first = $record->refresh()->getRawOriginal($pathColumn);
    pictureUpload($url, UploadedFile::fake()->image('b.png', 400, 400))->assertOk();
    $second = $record->refresh()->getRawOriginal($pathColumn);

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);

    asStaff(orderStaff('product-manager'))->deleteJson($url)->assertNoContent();
    $record->refresh();
    expect($record->getRawOriginal($pathColumn))->toBeNull()->and($record->{$urlColumn})->toBeNull();
    Storage::disk('public')->assertMissing($second);
})->with('owners');

it('refuses unsafe, tiny or oversized files and other roles', function (Closure $setup) {
    [$record, $segment, $field, $pathColumn] = $setup();
    $url = "/api/v1/admin/{$segment}/{$record->id}/{$field}";

    pictureUpload($url, UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml'))->assertJsonValidationErrors('file');
    pictureUpload($url, UploadedFile::fake()->image('tiny.png', 50, 50))->assertJsonValidationErrors('file');
    pictureUpload($url, UploadedFile::fake()->image('big.png', 800, 800)->size(6000))->assertJsonValidationErrors('file');
    pictureUpload($url, UploadedFile::fake()->image('ok.png', 400, 400), 'content-editor')->assertForbidden();
    asStaff(orderStaff('order-manager'))->deleteJson($url)->assertForbidden();

    expect($record->refresh()->getRawOriginal($pathColumn))->toBeNull();
})->with('owners');

it('keeps a deleted category or brand picture file for a later restore', function () {
    $category = Category::factory()->create();
    pictureUpload("/api/v1/admin/categories/{$category->id}/image", UploadedFile::fake()->image('a.png', 400, 400))->assertOk();
    $path = $category->refresh()->getRawOriginal('image_path');

    asStaff(orderStaff('product-manager'))->deleteJson("/api/v1/admin/categories/{$category->id}")->assertNoContent();

    Storage::disk('public')->assertExists($path);
});
