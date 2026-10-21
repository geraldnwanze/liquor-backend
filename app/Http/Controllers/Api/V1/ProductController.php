<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\ProductListResource;
use App\Http\Resources\Catalog\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'in_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:newest,price_asc,price_desc,name'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        $query = Product::query()->published()->withRating()
            ->with(['brand:id,name,slug', 'category:id,name,slug', 'images' => fn ($q) => $q->limit(1), 'variants'])
            ->when($validated['category'] ?? null, function ($q, string $slug) {
                // A parent category includes its children.
                $ids = Category::where('slug', $slug)->orWhereHas('parent', fn ($p) => $p->where('slug', $slug))->pluck('id');
                $q->whereIn('category_id', $ids);
            })
            ->when($validated['brand'] ?? null, fn ($q, string $slug) => $q->whereHas('brand', fn ($b) => $b->where('slug', $slug)))
            ->when($validated['q'] ?? null, fn ($q, string $term) => $this->search($q, $term))
            ->when(isset($validated['min_price']) || isset($validated['max_price']), fn ($q) => $q->whereHas('variants', function ($v) use ($validated) {
                $v->where('is_active', true)
                    ->when(isset($validated['min_price']), fn ($x) => $x->where('price_kobo', '>=', $validated['min_price'] * 100))
                    ->when(isset($validated['max_price']), fn ($x) => $x->where('price_kobo', '<=', $validated['max_price'] * 100));
            }))
            ->when($request->boolean('in_stock'), fn ($q) => $q->whereHas('variants', fn ($v) => $v->where('is_active', true)->whereColumn('on_hand', '>', 'reserved')));

        // With a search term and no chosen order, the best matches (name first, then brand or category) lead.
        if (($validated['q'] ?? null) && ! isset($validated['sort'])) {
            $this->orderByRelevance($query, $validated['q']);
        }

        match ($validated['sort'] ?? 'newest') {
            'name' => $query->orderBy('name'),
            'price_asc' => $query->orderBy($this->minPrice(), 'asc'),
            'price_desc' => $query->orderBy($this->minPrice(), 'desc'),
            default => $query->latest('id'),
        };

        return ProductListResource::collection($query->paginate($validated['per_page'] ?? 24)->withQueryString());
    }

    /**
     * Every word must match somewhere: the product name, brand, category (or its parent), or the description,
     * tasting notes and country. So "scotch whisky" finds a whiskey described as a single-malt scotch.
     *
     * @param  Builder<Product>  $query
     */
    private function search(Builder $query, string $term): void
    {
        foreach (array_slice(preg_split('/\s+/', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $query->where(fn (Builder $w) => $w
                ->where('name', 'like', $like)
                ->orWhere('short_description', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('tasting_notes', 'like', $like)
                ->orWhere('country_of_origin', 'like', $like)
                ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', $like))
                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', $like)->orWhereHas('parent', fn ($p) => $p->where('name', 'like', $like))));
        }
    }

    /** @param  Builder<Product>  $query */
    private function orderByRelevance(Builder $query, string $term): void
    {
        $like = '%'.addcslashes(trim($term), '%_\\').'%';
        $query->orderByRaw(
            'case when products.name like ? then 0 when exists (select 1 from brands where brands.id = products.brand_id and brands.name like ?) '
            .'or exists (select 1 from categories where categories.id = products.category_id and categories.name like ?) then 1 else 2 end',
            [$like, $like, $like],
        );
    }

    /** Products with paid sales in the last 90 days, most units first. Empty until something has sold. */
    public function bestSellers(Request $request): AnonymousResourceCollection
    {
        $limit = min(24, max(1, (int) $request->integer('limit', 8)));
        $sold = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->where('orders.status', OrderStatus::Paid->value)
            ->where('orders.paid_at', '>=', now()->subDays(90))
            ->groupBy('product_variants.product_id')
            ->orderByRaw('sum(order_items.quantity) desc')
            ->limit($limit)
            ->pluck('product_variants.product_id');

        $products = Product::query()->published()->withRating()
            ->with(['brand:id,name,slug', 'category:id,name,slug', 'images' => fn ($q) => $q->limit(1), 'variants'])
            ->whereIn('id', $sold)->get()
            ->sortBy(fn (Product $p) => $sold->search($p->id))->values();

        return ProductListResource::collection($products);
    }

    public function show(string $slug): ProductResource
    {
        $product = Product::query()->published()->withRating()
            ->with(['brand', 'category', 'images', 'variants'])
            ->where('slug', $slug)
            ->firstOrFail();

        return new ProductResource($product);
    }

    private function minPrice(): Expression
    {
        return DB::raw('(select min(price_kobo) from product_variants where product_variants.product_id = products.id and product_variants.deleted_at is null and is_active = 1)');
    }
}
