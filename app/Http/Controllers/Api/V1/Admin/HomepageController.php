<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HomepageRequest;
use App\Services\Administration\AuditLogger;
use App\Services\Administration\HomepageContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomepageController extends Controller
{
    public function __construct(private HomepageContent $content, private AuditLogger $audit) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->content->values()]);
    }

    public function update(HomepageRequest $request): JsonResponse
    {
        $before = $this->content->values();
        $this->content->save($request->validated());
        $after = $this->content->values();
        $this->audit->record('homepage.updated', null, ['changed' => array_keys(array_filter($after, fn ($v, $k) => $v !== $before[$k], ARRAY_FILTER_USE_BOTH))]);

        return response()->json(['data' => $after]);
    }

    public function uploadHeroImage(Request $request): JsonResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp', 'dimensions:min_width=800,min_height=400,max_width=6000,max_height=6000']],
            ['file.max' => 'The picture must be 5 MB or smaller.', 'file.mimes' => 'Upload a PNG, JPEG or WebP picture.', 'file.dimensions' => 'The picture must be at least 800 × 400 pixels.', 'file.uploaded' => 'The upload failed. Check the file is under 5 MB and try again.'],
        );

        $url = $this->content->replaceHeroImage($request->file('file'));
        $this->audit->record('homepage.hero_image_updated');

        return response()->json(['data' => ['hero_image_url' => $url]]);
    }

    public function removeHeroImage(): JsonResponse
    {
        $this->content->removeHeroImage();
        $this->audit->record('homepage.hero_image_removed');

        return response()->json(null, 204);
    }
}
