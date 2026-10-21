<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\JsonResponse;

/** Published information pages for the storefront. Drafts are never visible here. */
class PageController extends Controller
{
    /** Pages to link from the footer. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Page::published()->where('show_in_footer', true)->orderBy('sort_order')->orderBy('title')->get(['title', 'slug'])]);
    }

    public function show(string $slug): JsonResponse
    {
        $page = Page::published()->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => ['title' => $page->title, 'slug' => $page->slug, 'body' => $page->body, 'meta_description' => $page->meta_description, 'updated_at' => $page->updated_at->toIso8601String()]]);
    }
}
