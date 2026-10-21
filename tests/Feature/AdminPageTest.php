<?php

use App\Models\AuditLog;
use App\Models\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('lets content staff create, edit, publish and delete pages, and records it', function () {
    $editor = orderStaff('content-editor');

    $id = asStaff($editor)->postJson('/api/v1/admin/pages', ['title' => 'Delivery & returns', 'body' => "We deliver across Lagos.\n\n## Returns\nContact us within 7 days."])->assertCreated()->assertJsonPath('data.slug', 'delivery-returns')->assertJsonPath('data.status', 'draft')->json('data.id');

    guest()->getJson('/api/v1/pages/delivery-returns')->assertNotFound();

    asStaff($editor)->putJson("/api/v1/admin/pages/{$id}", ['status' => 'published'])->assertOk();
    guest()->getJson('/api/v1/pages/delivery-returns')->assertOk()->assertJsonPath('data.title', 'Delivery & returns');

    asStaff($editor)->deleteJson("/api/v1/admin/pages/{$id}")->assertNoContent();
    expect(Page::count())->toBe(0)->and(AuditLog::whereIn('action', ['page.created', 'page.updated', 'page.deleted'])->count())->toBe(3);
});

it('lists only published footer pages, in order', function () {
    Page::factory()->create(['title' => 'B page', 'slug' => 'b', 'sort_order' => 2]);
    Page::factory()->create(['title' => 'A page', 'slug' => 'a', 'sort_order' => 1]);
    Page::factory()->draft()->create(['slug' => 'hidden']);
    Page::factory()->create(['slug' => 'not-in-footer', 'show_in_footer' => false]);

    guest()->getJson('/api/v1/pages')->assertOk()->assertJsonPath('data.*.slug', ['a', 'b']);
});

it('keeps slugs unique, tidy and plain text', function () {
    $editor = orderStaff('content-editor');
    Page::factory()->create(['slug' => 'faq']);

    asStaff($editor)->postJson('/api/v1/admin/pages', ['title' => 'FAQ', 'slug' => 'faq', 'body' => 'Some answers to questions.'])->assertJsonValidationErrors('slug');
    asStaff($editor)->postJson('/api/v1/admin/pages', ['title' => 'Bad', 'slug' => 'Not A Slug!', 'body' => 'Some answers to questions.'])->assertJsonValidationErrors('slug');
    asStaff($editor)->postJson('/api/v1/admin/pages', ['title' => 'Only markup', 'body' => '<script>alert(1)</script><b></b>'])->assertJsonValidationErrors('body');

    asStaff($editor)->postJson('/api/v1/admin/pages', ['title' => '<b>Sizing</b> guide', 'body' => 'Hello <script>x</script>there, this is fine.'])->assertCreated()->assertJsonPath('data.title', 'Sizing guide');
    expect(Page::where('slug', 'sizing-guide')->value('body'))->not->toContain('<');
});

it('keeps page editing away from other roles and customers', function () {
    $page = Page::factory()->create();

    asStaff(orderStaff('order-manager'))->postJson('/api/v1/admin/pages', ['title' => 'X page', 'body' => 'Some body text here.'])->assertForbidden();
    asStaff(orderStaff('customer-support'))->deleteJson("/api/v1/admin/pages/{$page->id}")->assertForbidden();
    guest()->putJson("/api/v1/admin/pages/{$page->id}", ['title' => 'Hacked'])->assertUnauthorized();
    expect($page->refresh()->title)->not->toBe('Hacked');
});

describe('pictures inside pages', function () {
    beforeEach(fn () => Storage::fake('public'));

    it('stores a picture as WebP and returns its address for the page text', function () {
        $res = asStaff(orderStaff('content-editor'))->post('/api/v1/admin/pages/images', ['file' => UploadedFile::fake()->image('hero.png', 1800, 900)], ['Accept' => 'application/json'])->assertCreated();

        expect($res->json('data.url'))->toBeString()->toContain('pages/');
        $path = collect(Storage::disk('public')->allFiles('pages'))->first();
        expect(getimagesizefromstring(Storage::disk('public')->get($path))['mime'])->toBe('image/webp')
            ->and(getimagesizefromstring(Storage::disk('public')->get($path))[0])->toBe(1600);
        expect(AuditLog::where('action', 'page.image_uploaded')->count())->toBe(1);
    });

    it('refuses unsafe or tiny files and people who cannot edit pages', function () {
        $editor = orderStaff('content-editor');
        foreach ([UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml'), UploadedFile::fake()->image('tiny.png', 40, 40), UploadedFile::fake()->image('big.png', 800, 800)->size(6000)] as $file) {
            asStaff($editor)->post('/api/v1/admin/pages/images', ['file' => $file], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
        }
        asStaff(orderStaff('order-manager'))->post('/api/v1/admin/pages/images', ['file' => UploadedFile::fake()->image('a.png', 400, 400)], ['Accept' => 'application/json'])->assertForbidden();
        guest()->post('/api/v1/admin/pages/images', [], ['Accept' => 'application/json'])->assertUnauthorized();
        expect(Storage::disk('public')->allFiles('pages'))->toBe([]);
    });
});
