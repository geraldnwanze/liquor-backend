<?php

namespace App\Services\Catalog;

use App\Exceptions\CommerceException;
use App\Models\Product;
use App\Models\ProductImage;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns an uploaded photo into a stored product image.
 *
 * Every upload is decoded and re-encoded as WebP, never stored as sent. That removes metadata (phone photos carry
 * GPS positions), fixes rotation, caps the size, and means a file that merely *claims* to be an image (or hides a
 * script inside valid image data) cannot be served back as it arrived. SVG is refused up front: it can carry script.
 */
class ProductImageStore
{
    public const MAX_SIDE = 1600;

    private const QUALITY = 85;

    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function store(UploadedFile $file, Product $product, ?string $alt, int $sortOrder): ProductImage
    {
        $path = $this->putFile($file, "products/{$product->id}");

        return $product->images()->create(['path' => $path, 'alt' => $alt, 'sort_order' => $sortOrder]);
    }

    /** Re-encodes and stores an upload under `$directory` (for example review photos) and returns its stored path. */
    public function putFile(UploadedFile $file, string $directory, int $maxSide = self::MAX_SIDE): string
    {
        $bytes = $this->encode($file, $maxSide);
        $path = trim($directory, '/').'/'.Str::ulid().'.webp';
        if (! $this->disk()->put($path, $bytes, 'public')) {
            throw new CommerceException('image_not_stored', "The image couldn't be saved. Please try again.", 500);
        }

        return $path;
    }

    /** The public address of a stored file. */
    public function urlFor(string $path): string
    {
        return $this->disk()->url($path);
    }

    /** Removes a stored file by path. Missing files are ignored. */
    public function deletePath(string $path): void
    {
        $this->disk()->delete($path);
    }

    /** Copies an uploaded image's file for a duplicated product, so deleting one never breaks the other. */
    public function copy(ProductImage $image, Product $to): ProductImage
    {
        $path = $image->getRawOriginal('path');
        if (! $path) {
            return $to->images()->create(['url' => $image->getRawOriginal('url'), 'alt' => $image->alt, 'sort_order' => $image->sort_order]);
        }
        $copy = "products/{$to->id}/".Str::ulid().'.webp';
        $this->disk()->copy($path, $copy);

        return $to->images()->create(['path' => $copy, 'alt' => $image->alt, 'sort_order' => $image->sort_order]);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.product_images_disk'));
    }

    private function encode(UploadedFile $file, int $maxSide = self::MAX_SIDE): string
    {
        $info = @getimagesize($file->getRealPath());
        if ($info === false || ! in_array($info[2], self::ALLOWED_TYPES, true)) {
            throw new CommerceException('image_invalid', 'That file is not a JPEG, PNG or WebP image.', 422);
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $source instanceof GdImage) {
            throw new CommerceException('image_invalid', "That image couldn't be read. Try saving it again as a JPEG or PNG.", 422);
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $source = $this->orient($source, $file->getRealPath());
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxSide / max($width, $height));
        if ($scale < 1) {
            $source = $this->resample($source, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        }

        imagepalettetotruecolor($source);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        ob_start();
        imagewebp($source, null, self::QUALITY);
        $bytes = (string) ob_get_clean();

        if ($bytes === '') {
            throw new CommerceException('image_invalid', "That image couldn't be processed.", 422);
        }

        return $bytes;
    }

    /** A smaller copy, keeping transparency. Fails loudly rather than storing an image that is still too large. */
    private function resample(GdImage $source, int $width, int $height): GdImage
    {
        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        if (! imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source))) {
            throw new CommerceException('image_invalid', "That image couldn't be resized.", 422);
        }

        return $target;
    }

    /** Phones store the real rotation in EXIF rather than in the pixels; apply it before the metadata is dropped. */
    private function orient(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
