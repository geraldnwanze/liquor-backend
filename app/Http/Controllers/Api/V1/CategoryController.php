<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Catalog\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        // Cache the resolved JSON array, never Eloquent models: persistent cache stores refuse to unserialize them.
        $categories = Cache::remember('catalog.categories', 300, fn () => CategoryResource::collection(
            Category::active()->whereNull('parent_id')->with('children')->orderBy('sort_order')->get()
        )->response()->getData(true)['data']);

        return response()->json(['data' => $categories]);
    }

    public function show(string $slug): CategoryResource
    {
        return new CategoryResource(Category::active()->with(['children', 'parent'])->where('slug', $slug)->firstOrFail());
    }
}
