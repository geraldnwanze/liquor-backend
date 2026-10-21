<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BrandRequest;
use App\Models\Brand;
use App\Services\Administration\AuditLogger;
use App\Services\Catalog\OwnedImage;
use App\Support\UniqueSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class BrandController extends Controller
{
    public function __construct(private AuditLogger $audit, private OwnedImage $pictures) {}

    public function index(): AnonymousResourceCollection
    {
        return JsonResource::collection(Brand::query()->withCount('products')->orderBy('name')->paginate(50));
    }

    public function show(Brand $brand): JsonResource
    {
        return new JsonResource($brand);
    }

    public function store(BrandRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = UniqueSlug::make(Brand::class, $data['slug'] ?? $data['name']);
        $brand = Brand::create($data)->refresh();
        $this->audit->record('brand.created', $brand, ['name' => $brand->name]);

        return (new JsonResource($brand))->response()->setStatusCode(201);
    }

    public function update(BrandRequest $request, Brand $brand): JsonResource
    {
        $data = $request->validated();
        if (array_key_exists('slug', $data)) {
            $data['slug'] = UniqueSlug::make(Brand::class, $data['slug'] ?: ($data['name'] ?? $brand->name), $brand->id);
        }
        $brand->fill($data)->save();
        $this->audit->record('brand.updated', $brand, ['changed' => array_keys($brand->getChanges())]);

        return new JsonResource($brand);
    }

    public function destroy(Brand $brand): JsonResponse
    {
        if ($brand->products()->exists()) {
            return response()->json([
                'message' => 'This brand still has products. Reassign them first.',
                'code' => 'brand_not_empty',
            ], 409);
        }

        $this->audit->record('brand.deleted', $brand, ['name' => $brand->name]);
        $brand->delete();

        return response()->json(null, 204);
    }

    public function uploadLogo(Request $request, Brand $brand): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp', 'dimensions:min_width=200,min_height=200,max_width=4500,max_height=4500']], ['file.max' => 'The picture must be 5 MB or smaller.', 'file.mimes' => 'Upload a PNG, JPEG or WebP picture.', 'file.dimensions' => 'The picture must be between 200 and 4500 pixels on each side.', 'file.uploaded' => 'The upload failed. Check the file is under 5 MB and try again.']);

        $this->pictures->replace($brand, 'logo_path', $request->file('file'), 'brands/'.$brand->id, 800);
        $this->audit->record('brand.logo_updated', $brand);

        return response()->json(['data' => ['logo_url' => $brand->refresh()->logo_url]]);
    }

    public function removeLogo(Brand $brand): JsonResponse
    {
        $this->pictures->clear($brand, 'logo_path', 'logo_url');
        $this->audit->record('brand.logo_removed', $brand);

        return response()->json(null, 204);
    }
}
