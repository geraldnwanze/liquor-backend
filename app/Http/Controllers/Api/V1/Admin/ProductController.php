<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Http\Resources\Admin\AdminProductResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Administration\AuditLogger;
use App\Services\Catalog\ProductImageStore;
use App\Support\UniqueSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function __construct(private AuditLogger $audit, private ProductImageStore $images) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:draft,active,archived,discontinued'],
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'uuid'],
            'brand_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->with(['brand:id,name,slug', 'category:id,name,slug', 'variants'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($filters['brand_id'] ?? null, fn ($q, $v) => $q->where('brand_id', $v))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)
                    ->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', $like)));
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return AdminProductResource::collection($products);
    }

    public function show(Product $product): AdminProductResource
    {
        return new AdminProductResource($product->load(['brand', 'category', 'variants', 'images']));
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (($data['status'] ?? 'draft') === ProductStatus::Active->value) {
            return $this->notPublishable();
        }

        $data['slug'] = UniqueSlug::make(Product::class, $data['slug'] ?? $data['name']);
        $product = Product::create($data)->refresh();
        $this->audit->record('product.created', $product, ['name' => $product->name]);

        return (new AdminProductResource($product->load(['brand', 'category'])))->response()->setStatusCode(201);
    }

    public function update(ProductRequest $request, Product $product): JsonResponse|AdminProductResource
    {
        $data = $request->validated();

        if (($data['status'] ?? null) === ProductStatus::Active->value
            && ! $product->variants()->where('is_active', true)->exists()) {
            return $this->notPublishable();
        }

        if (array_key_exists('slug', $data)) {
            $data['slug'] = UniqueSlug::make(Product::class, $data['slug'] ?: ($data['name'] ?? $product->name), $product->id);
        }

        $product->fill($data)->save();
        $changed = array_keys($product->getChanges());
        $this->audit->record('product.updated', $product, ['changed' => $changed]);

        return new AdminProductResource($product->load(['brand', 'category', 'variants', 'images']));
    }

    public function archive(Product $product): AdminProductResource
    {
        $product->update(['status' => ProductStatus::Archived]);
        $this->audit->record('product.archived', $product);

        return new AdminProductResource($product);
    }

    public function destroy(Product $product): JsonResponse
    {
        if (! in_array($product->status, [ProductStatus::Draft, ProductStatus::Archived], true)) {
            return response()->json([
                'message' => 'Only draft or archived products can be deleted. Archive the product first.',
                'code' => 'product_not_deletable',
            ], 409);
        }

        $this->audit->record('product.deleted', $product, ['name' => $product->name, 'slug' => $product->slug]);
        // A soft delete: the product, its variants, photos and reviews are hidden, and their files stay in storage.
        $product->delete();

        return response()->json(null, 204);
    }

    /** Copies a product as a draft: variants get new SKUs and zero stock; images are copied. */
    public function duplicate(Product $product): JsonResponse
    {
        $copy = DB::transaction(function () use ($product): Product {
            $copy = $product->replicate();
            $copy->name = $product->name.' (copy)';
            $copy->slug = UniqueSlug::make(Product::class, $product->slug.'-copy');
            $copy->status = ProductStatus::Draft;
            $copy->is_featured = false;
            $copy->save();

            foreach ($product->variants as $variant) {
                $new = $variant->replicate(['on_hand', 'reserved']);
                $new->product_id = $copy->id;
                $new->sku = $this->uniqueSku($variant->sku.'-COPY');
                $new->on_hand = 0;
                $new->reserved = 0;
                $new->save();
            }
            foreach ($product->images as $image) {
                $this->images->copy($image, $copy);
            }

            return $copy;
        });

        $this->audit->record('product.duplicated', $copy, ['source_id' => $product->id]);

        return (new AdminProductResource($copy->load(['variants', 'images'])))->response()->setStatusCode(201);
    }

    private function uniqueSku(string $base): string
    {
        $sku = $base;
        for ($i = 2; ProductVariant::where('sku', $sku)->exists(); $i++) {
            $sku = $base.'-'.$i;
        }

        return $sku;
    }

    private function notPublishable(): JsonResponse
    {
        return response()->json([
            'message' => 'A product needs at least one active variant before it can be published.',
            'code' => 'product_not_publishable',
        ], 422);
    }
}
