<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\Category;
use App\Services\Administration\AuditLogger;
use App\Services\Catalog\OwnedImage;
use App\Support\UniqueSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource as Resource;

class CategoryController extends Controller
{
    public function __construct(private AuditLogger $audit, private OwnedImage $pictures) {}

    public function index(): AnonymousResourceCollection
    {
        return Resource::collection(Category::query()->withCount('products')->orderBy('parent_id')->orderBy('sort_order')->get());
    }

    public function show(Category $category): Resource
    {
        return new Resource($category);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = UniqueSlug::make(Category::class, $data['slug'] ?? $data['name']);
        $category = Category::create($data)->refresh();
        $this->audit->record('category.created', $category, ['name' => $category->name]);

        return (new Resource($category))->response()->setStatusCode(201);
    }

    public function update(CategoryRequest $request, Category $category): Resource
    {
        $data = $request->validated();
        if (array_key_exists('slug', $data)) {
            $data['slug'] = UniqueSlug::make(Category::class, $data['slug'] ?: ($data['name'] ?? $category->name), $category->id);
        }
        $category->fill($data)->save();
        $this->audit->record('category.updated', $category, ['changed' => array_keys($category->getChanges())]);

        return new Resource($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->products()->exists() || $category->children()->exists()) {
            return response()->json([
                'message' => 'This category still has products or sub-categories. Move or remove them first.',
                'code' => 'category_not_empty',
            ], 409);
        }

        $this->audit->record('category.deleted', $category, ['name' => $category->name]);
        $category->delete();

        return response()->json(null, 204);
    }

    public function uploadImage(Request $request, Category $category): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp', 'dimensions:min_width=200,min_height=200,max_width=4500,max_height=4500']], ['file.max' => 'The picture must be 5 MB or smaller.', 'file.mimes' => 'Upload a PNG, JPEG or WebP picture.', 'file.dimensions' => 'The picture must be between 200 and 4500 pixels on each side.', 'file.uploaded' => 'The upload failed. Check the file is under 5 MB and try again.']);

        $this->pictures->replace($category, 'image_path', $request->file('file'), 'categories/'.$category->id, 1200);
        $this->audit->record('category.image_updated', $category);

        return response()->json(['data' => ['image_url' => $category->refresh()->image_url]]);
    }

    public function removeImage(Category $category): JsonResponse
    {
        $this->pictures->clear($category, 'image_path', 'image_url');
        $this->audit->record('category.image_removed', $category);

        return response()->json(null, 204);
    }
}
