<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\CommerceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Administration\AuditLogger;
use App\Services\Catalog\ProductImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProductImageController extends Controller
{
    private const MAX_PER_PRODUCT = 10;

    public function __construct(private AuditLogger $audit, private ProductImageStore $store) {}

    public function store(ImageRequest $request, Product $product): JsonResponse
    {
        if ($product->images()->count() >= self::MAX_PER_PRODUCT) {
            throw new CommerceException('image_limit', 'A product can have at most '.self::MAX_PER_PRODUCT.' images. Remove one first.', 422);
        }
        $data = $request->validated();
        $sort = (int) ($data['sort_order'] ?? ((int) $product->images()->max('sort_order') + 1));

        $image = $request->hasFile('file')
            ? $this->store->store($request->file('file'), $product, $data['alt'] ?? null, $sort)
            : $product->images()->create(['url' => $data['url'], 'alt' => $data['alt'] ?? null, 'sort_order' => $sort]);

        $this->audit->record('product_image.created', $image, ['product_id' => $product->id, 'uploaded' => $request->hasFile('file')]);

        return response()->json(['data' => $this->present($image->refresh())], 201);
    }

    public function update(ImageRequest $request, ProductImage $image): JsonResponse
    {
        $image->fill($request->validated())->save();
        $this->audit->record('product_image.updated', $image, ['product_id' => $image->product_id]);

        return response()->json(['data' => $this->present($image->refresh())]);
    }

    /** Makes this the main image (the one shop pages show first) and renumbers the rest in their current order. */
    public function makePrimary(ProductImage $image): JsonResponse
    {
        DB::transaction(function () use ($image): void {
            $others = ProductImage::query()->where('product_id', $image->product_id)->whereKeyNot($image->id)->orderBy('sort_order')->orderBy('id')->pluck('id');
            $image->update(['sort_order' => 0]);
            foreach ($others as $i => $id) {
                ProductImage::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });
        $this->audit->record('product_image.made_primary', $image, ['product_id' => $image->product_id]);

        return response()->json(['data' => ProductImage::where('product_id', $image->product_id)->orderBy('sort_order')->get()->map(fn ($i) => $this->present($i))->values()]);
    }

    public function destroy(ProductImage $image): JsonResponse
    {
        $this->audit->record('product_image.deleted', $image, ['product_id' => $image->product_id]);
        $image->delete(); // soft delete: the file stays in storage

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(ProductImage $image): array
    {
        return $image->only(['id', 'url', 'alt', 'sort_order']);
    }
}
