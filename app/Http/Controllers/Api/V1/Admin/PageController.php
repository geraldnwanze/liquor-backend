<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PageRequest;
use App\Models\Page;
use App\Services\Administration\AuditLogger;
use App\Services\Catalog\ProductImageStore;
use App\Support\UniqueSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function __construct(private AuditLogger $audit, private ProductImageStore $images) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Page::orderBy('sort_order')->orderBy('title')->get()->map(fn (Page $p) => $this->present($p))]);
    }

    public function show(Page $page): JsonResponse
    {
        return response()->json(['data' => $this->present($page)]);
    }

    public function store(PageRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = UniqueSlug::make(Page::class, $data['slug'] ?? $data['title']);
        $page = Page::create($data + ['updated_by' => $request->user()->id])->refresh();
        $this->audit->record('page.created', $page, ['title' => $page->title, 'status' => $page->status]);

        return response()->json(['data' => $this->present($page)], 201);
    }

    public function update(PageRequest $request, Page $page): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('slug', $data)) {
            $data['slug'] = UniqueSlug::make(Page::class, $data['slug'] ?: ($data['title'] ?? $page->title), $page->id);
        }
        $page->fill($data + ['updated_by' => $request->user()->id])->save();
        $this->audit->record('page.updated', $page, ['changed' => array_keys($page->getChanges())]);

        return response()->json(['data' => $this->present($page->refresh())]);
    }

    public function destroy(Page $page): JsonResponse
    {
        $this->audit->record('page.deleted', $page, ['title' => $page->title, 'slug' => $page->slug]);
        $page->delete();

        return response()->json(null, 204);
    }

    /** A picture for use inside a page's text. Re-encoded to WebP; the returned address goes into the page as an image. */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate(
            ['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp', 'dimensions:min_width=100,min_height=100,max_width=4500,max_height=4500']],
            ['file.max' => 'The picture must be 5 MB or smaller.', 'file.mimes' => 'Upload a PNG, JPEG or WebP picture.', 'file.dimensions' => 'The picture must be between 100 and 4500 pixels on each side.', 'file.uploaded' => 'The upload failed. Check the file is under 5 MB and try again.'],
        );

        $path = $this->images->putFile($request->file('file'), 'pages', 1600);
        $this->audit->record('page.image_uploaded', null, ['path' => $path]);

        return response()->json(['data' => ['url' => $this->images->urlFor($path)]], 201);
    }

    /** @return array<string, mixed> */
    private function present(Page $p): array
    {
        return ['id' => $p->id, 'title' => $p->title, 'slug' => $p->slug, 'body' => $p->body, 'meta_description' => $p->meta_description, 'status' => $p->status, 'show_in_footer' => $p->show_in_footer, 'sort_order' => $p->sort_order, 'updated_at' => $p->updated_at->toIso8601String()];
    }
}
