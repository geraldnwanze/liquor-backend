<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\ProductListResource;
use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WishlistController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()->published()->withRating()
            ->with(['brand:id,name,slug', 'category:id,name,slug', 'images' => fn ($q) => $q->limit(1), 'variants'])
            ->whereIn('id', WishlistItem::where('user_id', $request->user()->id)->select('product_id'))
            ->latest('id')->limit(100)->get();

        return ProductListResource::collection($products);
    }

    /** Just the slugs, so product cards can show which hearts are filled without loading whole products. */
    public function slugs(Request $request): JsonResponse
    {
        $slugs = Product::query()->published()
            ->whereIn('id', WishlistItem::where('user_id', $request->user()->id)->select('product_id'))
            ->pluck('slug');

        return response()->json(['data' => $slugs]);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $product = Product::query()->published()->where('slug', $slug)->firstOrFail();
        $item = WishlistItem::withTrashed()->firstOrCreate(['user_id' => $request->user()->id, 'product_id' => $product->id]);
        if ($item->trashed()) {
            $item->restore();
        }

        return response()->json(['data' => ['saved' => true]], 201);
    }

    public function destroy(Request $request, string $slug): JsonResponse
    {
        WishlistItem::where('user_id', $request->user()->id)
            ->whereIn('product_id', Product::where('slug', $slug)->select('id'))
            ->delete();

        return response()->json(null, 204);
    }
}
