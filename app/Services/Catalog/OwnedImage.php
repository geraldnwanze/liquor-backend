<?php

namespace App\Services\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * One uploaded picture that belongs to a record (a category's image, a brand's logo): re-encoded to WebP like every
 * other upload, stored on the image disk, and the previous file removed when it is replaced. The path lives in the
 * model's own column; the model's URL accessor resolves it.
 */
class OwnedImage
{
    public function __construct(private ProductImageStore $images) {}

    public function replace(Model $model, string $pathColumn, UploadedFile $file, string $directory, int $maxSide): void
    {
        $old = $model->getRawOriginal($pathColumn);
        $model->forceFill([$pathColumn => $this->images->putFile($file, $directory, $maxSide)])->save();
        if ($old) {
            $this->images->deletePath($old);
        }
    }

    /** Removes the uploaded file and any pasted address too, so the picture is really gone from the shop. */
    public function clear(Model $model, string $pathColumn, string $urlColumn): void
    {
        $old = $model->getRawOriginal($pathColumn);
        $model->forceFill([$pathColumn => null, $urlColumn => null])->save();
        if ($old) {
            $this->images->deletePath($old);
        }
    }
}
