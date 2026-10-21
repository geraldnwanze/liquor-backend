<?php

use App\Enums\ProductStatus;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('public');
});

function uploadTo(Product $product, UploadedFile $file, array $extra = [], ?User $as = null): TestResponse
{
    return asStaff($as ?? orderStaff('product-manager'))->post("/api/v1/admin/products/{$product->id}/images", ['file' => $file, ...$extra], ['Accept' => 'application/json']);
}

/** Decodes what was actually stored: [width, height, mime]. */
function storedInfo(string $path): array
{
    $info = getimagesizefromstring(Storage::disk('public')->get($path));

    return [$info[0], $info[1], $info['mime']];
}

/** A JPEG whose EXIF says "rotate 90 degrees clockwise" (orientation 6), like a phone photo held upright. */
function jpegWithOrientation(int $width, int $height, int $orientation): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();
    $tiff = "II*\x00\x08\x00\x00\x00".pack('v', 1).pack('vvVv', 0x0112, 3, 1, $orientation)."\x00\x00".pack('V', 0);
    $app1 = "Exif\x00\x00".$tiff;
    $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);

    return UploadedFile::fake()->createWithContent('phone.jpg', $withExif);
}

describe('uploading a photo', function () {
    it('stores it as WebP on the images disk and returns an address the shop can load', function () {
        $product = Product::factory()->create();

        $res = uploadTo($product, UploadedFile::fake()->image('bottle.jpg', 1000, 800), ['alt' => 'A bottle on a table'])->assertCreated()
            ->assertJsonPath('data.alt', 'A bottle on a table');

        $image = ProductImage::firstWhere('product_id', $product->id);
        Storage::disk('public')->assertExists($image->path);
        expect($image->path)->toStartWith("products/{$product->id}/")->toEndWith('.webp')
            ->and(storedInfo($image->path))->toBe([1000, 800, 'image/webp'])
            ->and($res->json('data.url'))->toContain($image->path)
            ->and($image->getRawOriginal('url'))->toBeNull();
        expect(AuditLog::where('action', 'product_image.created')->count())->toBe(1);
    });

    it('shrinks large photos to 1600 pixels on the long side, keeps proportions, and never enlarges small ones', function () {
        $product = Product::factory()->create();

        uploadTo($product, UploadedFile::fake()->image('big.jpg', 3200, 2000))->assertCreated();
        uploadTo($product, UploadedFile::fake()->image('small.png', 800, 600))->assertCreated();

        [$big, $small] = ProductImage::where('product_id', $product->id)->orderBy('id')->get();
        expect(storedInfo($big->path))->toBe([1600, 1000, 'image/webp'])->and(storedInfo($small->path))->toBe([800, 600, 'image/webp']);
    });

    it('keeps transparency in PNG photos', function () {
        $product = Product::factory()->create();
        $png = imagecreatetruecolor(600, 600);
        imagealphablending($png, false);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, imagecolorallocatealpha($png, 255, 255, 255, 127));
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();

        uploadTo($product, UploadedFile::fake()->createWithContent('cutout.png', $bytes))->assertCreated();

        $stored = imagecreatefromstring(Storage::disk('public')->get(ProductImage::first()->path));
        expect((imagecolorat($stored, 5, 5) >> 24) & 0x7F)->toBeGreaterThan(100); // still (mostly) transparent
    });

    it('applies the rotation a phone recorded in EXIF, then drops the metadata', function () {
        $product = Product::factory()->create();

        uploadTo($product, jpegWithOrientation(800, 500, 6))->assertCreated();

        $path = ProductImage::first()->path;
        expect(storedInfo($path))->toBe([500, 800, 'image/webp']);
        expect(Storage::disk('public')->get($path))->not->toContain('Exif');
    });

    it('does not keep anything hidden inside the file it was sent', function () {
        $product = Product::factory()->create();
        $png = UploadedFile::fake()->image('trick.png', 600, 600);
        $payload = file_get_contents($png->getRealPath()).'<?php echo "pwned"; ?><script>alert(1)</script>';

        uploadTo($product, UploadedFile::fake()->createWithContent('trick.png', $payload))->assertCreated();

        $stored = Storage::disk('public')->get(ProductImage::first()->path);
        expect($stored)->not->toContain('<?php')->not->toContain('<script')->and(substr($stored, 0, 4))->toBe('RIFF');
    });
});

describe('refusing bad uploads', function () {
    it('refuses files that are not JPEG, PNG or WebP photos', function (string $name, string $content) {
        $product = Product::factory()->create();

        uploadTo($product, UploadedFile::fake()->createWithContent($name, $content))->assertUnprocessable()->assertJsonValidationErrors('file');
        expect(ProductImage::count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);
    })->with([
        'an SVG (can carry script)' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        'text renamed to .png' => ['photo.png', 'definitely not an image'],
        'a PHP file renamed to .jpg' => ['shell.jpg', '<?php system($_GET["c"]);'],
        'an HTML file renamed to .webp' => ['page.webp', '<html><body>hi</body></html>'],
    ]);

    it('refuses GIFs, tiny photos, huge dimensions and oversized files', function (UploadedFile $file) {
        $product = Product::factory()->create();

        uploadTo($product, $file)->assertUnprocessable()->assertJsonValidationErrors('file');
        expect(ProductImage::count())->toBe(0);
    })->with([
        'a GIF' => [fn () => UploadedFile::fake()->image('a.gif', 800, 800)],
        'a photo under 400 pixels' => [fn () => UploadedFile::fake()->image('tiny.jpg', 300, 300)],
        'a photo over 4500 pixels wide' => [fn () => UploadedFile::fake()->image('wide.jpg', 4600, 1000)],
        'a file over 5 MB' => [fn () => UploadedFile::fake()->image('heavy.jpg', 800, 800)->size(6000)],
    ]);

    it('needs a file or an address, not both', function () {
        $product = Product::factory()->create();
        $staff = orderStaff('product-manager');

        asStaff($staff)->postJson("/api/v1/admin/products/{$product->id}/images", [])->assertUnprocessable();
        asStaff($staff)->post("/api/v1/admin/products/{$product->id}/images", ['file' => UploadedFile::fake()->image('a.jpg', 800, 800), 'url' => 'https://cdn.test/a.jpg'], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        expect(ProductImage::count())->toBe(0);
    });

    it('stops at ten images per product', function () {
        $product = Product::factory()->create();
        ProductImage::factory()->count(10)->create(['product_id' => $product->id]);

        uploadTo($product, UploadedFile::fake()->image('eleventh.jpg', 800, 800))->assertUnprocessable()->assertJsonPath('code', 'image_limit');
        expect(ProductImage::count())->toBe(10);
    });

    it('is for catalogue staff only', function () {
        $product = Product::factory()->create();
        $photo = fn () => UploadedFile::fake()->image('a.jpg', 800, 800);

        $this->post("/api/v1/admin/products/{$product->id}/images", ['file' => $photo()], ['Accept' => 'application/json'])->assertUnauthorized();
        uploadTo($product, $photo(), as: User::factory()->create())->assertForbidden();
        uploadTo($product, $photo(), as: orderStaff('customer-support'))->assertForbidden();
        expect(Storage::disk('public')->allFiles())->toBe([]);
    });
});

describe('looking after the files', function () {
    it('soft-deletes an image and keeps its file, uploaded or linked', function () {
        $product = Product::factory()->create();
        $staff = orderStaff('product-manager');
        uploadTo($product, UploadedFile::fake()->image('a.jpg', 800, 800), as: $staff)->assertCreated();
        $uploaded = ProductImage::first();
        $linked = ProductImage::factory()->create(['product_id' => $product->id, 'url' => 'https://cdn.test/x.jpg']);

        asStaff($staff)->deleteJson("/api/v1/admin/images/{$uploaded->id}")->assertNoContent();
        asStaff($staff)->deleteJson("/api/v1/admin/images/{$linked->id}")->assertNoContent();

        expect(ProductImage::count())->toBe(0)->and(ProductImage::withTrashed()->count())->toBe(2);
        $this->assertSoftDeleted('product_images', ['id' => $uploaded->id]);
        Storage::disk('public')->assertExists($uploaded->getRawOriginal('path'));
    });

    it('soft-deletes a product with its photos and keeps every file', function () {
        $product = Product::factory()->create(['status' => ProductStatus::Draft]);
        $staff = orderStaff('product-manager');
        uploadTo($product, UploadedFile::fake()->image('a.jpg', 800, 800), as: $staff)->assertCreated();
        uploadTo($product, UploadedFile::fake()->image('b.jpg', 800, 800), as: $staff)->assertCreated();

        asStaff($staff)->deleteJson("/api/v1/admin/products/{$product->id}")->assertNoContent();

        expect(Storage::disk('public')->allFiles())->toHaveCount(2)->and(ProductImage::count())->toBe(0)->and(ProductImage::withTrashed()->count())->toBe(2);
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    });

    it('gives a duplicated product its own copies, so changing one never touches the other', function () {
        $product = Product::factory()->create();
        $staff = orderStaff('product-manager');
        uploadTo($product, UploadedFile::fake()->image('a.jpg', 800, 800), as: $staff)->assertCreated();

        $copyId = asStaff($staff)->postJson("/api/v1/admin/products/{$product->id}/duplicate")->assertCreated()->json('data.id');
        $original = ProductImage::firstWhere('product_id', $product->id);
        $copy = ProductImage::firstWhere('product_id', $copyId);

        expect($copy->path)->not->toBe($original->path);
        Storage::disk('public')->assertExists([$original->path, $copy->path]);

        asStaff($staff)->deleteJson("/api/v1/admin/images/{$original->id}")->assertNoContent();
        expect(ProductImage::find($copy->id))->not->toBeNull();
        Storage::disk('public')->assertExists([$original->path, $copy->path]);
    });
});

describe('the main image', function () {
    it('moves an image to the front and renumbers the rest in order', function () {
        $product = Product::factory()->create();
        $staff = orderStaff('product-manager');
        foreach ([0, 1, 2] as $i) {
            uploadTo($product, UploadedFile::fake()->image("p{$i}.jpg", 800, 800), ['alt' => "photo {$i}", 'sort_order' => ($i + 1) * 10], as: $staff)->assertCreated();
        }
        $third = ProductImage::where('alt', 'photo 2')->first();

        $res = asStaff($staff)->postJson("/api/v1/admin/images/{$third->id}/primary")->assertOk();

        expect(collect($res->json('data'))->pluck('alt')->all())->toBe(['photo 2', 'photo 0', 'photo 1'])
            ->and(collect($res->json('data'))->pluck('sort_order')->all())->toBe([0, 1, 2]);
        expect(AuditLog::where('action', 'product_image.made_primary')->count())->toBe(1);
    });

    it('only touches the images of that product, and only for catalogue staff', function () {
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $other = ProductImage::factory()->create(['product_id' => $b->id, 'sort_order' => 5, 'url' => 'https://cdn.test/b.jpg']);
        $mine = ProductImage::factory()->create(['product_id' => $a->id, 'sort_order' => 3, 'url' => 'https://cdn.test/a.jpg']);

        asStaff(orderStaff('customer-support'))->postJson("/api/v1/admin/images/{$mine->id}/primary")->assertForbidden();
        asStaff(orderStaff('product-manager'))->postJson("/api/v1/admin/images/{$mine->id}/primary")->assertOk();

        expect($other->refresh()->sort_order)->toBe(5)->and($mine->refresh()->sort_order)->toBe(0);
    });
});

describe('what shoppers see', function () {
    it('shows the uploaded photo on the product page and in listings, ahead of nothing', function () {
        $variant = sellableVariant();
        $product = $variant->product;
        uploadTo($product, UploadedFile::fake()->image('hero.jpg', 900, 900), ['alt' => 'The bottle'])->assertCreated();
        $stored = ProductImage::first();

        $page = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();
        $list = $this->getJson('/api/v1/products')->assertOk();

        expect($page->json('data.images.0.url'))->toContain($stored->path)->and($page->json('data.images.0.alt'))->toBe('The bottle')
            ->and($list->json('data.0.image.url'))->toContain($stored->path);
    });

    it('still shows an image linked by address exactly as pasted', function () {
        $variant = sellableVariant();
        ProductImage::factory()->create(['product_id' => $variant->product_id, 'url' => 'https://cdn.test/pasted.jpg', 'alt' => 'x']);

        $this->getJson("/api/v1/products/{$variant->product->slug}")->assertJsonPath('data.images.0.url', 'https://cdn.test/pasted.jpg');
    });
});
