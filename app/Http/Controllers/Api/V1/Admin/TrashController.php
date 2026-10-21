<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Administration\AuditLogger;
use App\Support\SoftDeletable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Everything staff have deleted, and a way to bring it back. Each record type is limited to the people who may manage
 * it (a page to content editors, a product to catalogue staff...), customers' own addresses and wishlists are not
 * listed (they are personal), and a record whose parent is still deleted must wait for the parent.
 */
class TrashController extends Controller
{
    /** @var array<string, array{permission: string, label: string, parent?: array{relation: string, name: string}}> */
    private const TYPES = [
        'product' => ['permission' => 'catalog.manage', 'label' => 'Product'],
        'variant' => ['permission' => 'catalog.manage', 'label' => 'Product variant', 'parent' => ['relation' => 'product', 'name' => 'product']],
        'image' => ['permission' => 'catalog.manage', 'label' => 'Product photo', 'parent' => ['relation' => 'product', 'name' => 'product']],
        'brand' => ['permission' => 'catalog.manage', 'label' => 'Brand'],
        'category' => ['permission' => 'catalog.manage', 'label' => 'Category', 'parent' => ['relation' => 'parent', 'name' => 'parent category']],
        'zone' => ['permission' => 'shipping.manage', 'label' => 'Delivery zone'],
        'discount' => ['permission' => 'promotions.manage', 'label' => 'Discount'],
        'campaign' => ['permission' => 'promotions.manage', 'label' => 'Newsletter draft'],
        'page' => ['permission' => 'content.manage', 'label' => 'Page'],
        'review' => ['permission' => 'reviews.moderate', 'label' => 'Review', 'parent' => ['relation' => 'product', 'name' => 'product']],
        'note' => ['permission' => 'customers.manage', 'label' => 'Support note'],
    ];

    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $only = $request->validate(['type' => ['nullable', 'string']])['type'] ?? null;
        $rows = collect();

        foreach ($this->allowedTypes($request) as $type => $meta) {
            if ($only && $only !== $type) {
                continue;
            }
            $model = SoftDeletable::TYPES[$type];
            $with = match ($type) {
                'variant', 'image', 'review' => ['product' => fn ($q) => $q->withTrashed()],
                default => [],
            };
            foreach ($model::onlyTrashed()->with($with)->latest('deleted_at')->limit(100)->get() as $record) {
                $rows->push(['type' => $type, 'type_label' => $meta['label'], 'id' => $record->id, 'name' => $this->label($type, $record), 'deleted_at' => $record->deleted_at, 'record' => $record]);
            }
        }

        $rows = $rows->sortByDesc('deleted_at')->take(200)->values();
        $who = $this->deletedBy($rows->pluck('id')->all());

        return response()->json([
            'data' => $rows->map(fn (array $r) => [
                'type' => $r['type'], 'type_label' => $r['type_label'], 'id' => $r['id'], 'name' => $r['name'],
                'deleted_at' => $r['deleted_at']->toIso8601String(), 'deleted_by' => $who[$r['id']] ?? null,
                'blocked_by' => $this->blockedBy($r['type'], $r['record']),
            ])->all(),
            'types' => collect($this->allowedTypes($request))->map(fn ($m, $t) => ['type' => $t, 'label' => $m['label']])->values(),
        ]);
    }

    public function restore(Request $request, string $type, string $id): JsonResponse
    {
        $meta = $this->allowedTypes($request)[$type] ?? null;
        abort_unless($meta, 404);
        $record = SoftDeletable::TYPES[$type]::onlyTrashed()->find($id);
        abort_unless($record, 404);

        if ($blocked = $this->blockedBy($type, $record)) {
            return response()->json(['message' => "Restore the {$blocked} first.", 'code' => 'parent_deleted'], 409);
        }

        $record->restore();
        $this->audit->record('trash.restored', $record, ['type' => $type]);

        return response()->json(['data' => ['type' => $type, 'id' => $record->id]]);
    }

    /** @return array<string, array{permission: string, label: string}> */
    private function allowedTypes(Request $request): array
    {
        return array_filter(self::TYPES, fn (array $m) => $request->user()->hasPermission($m['permission']));
    }

    /** Names the deleted parent that must be brought back first, if any. */
    private function blockedBy(string $type, Model $record): ?string
    {
        $parent = self::TYPES[$type]['parent'] ?? null;
        if ($parent && $record->{$parent['relation']}()->onlyTrashed()->exists()) {
            return $parent['name'];
        }
        if ($type === 'product') {
            foreach (['brand', 'category'] as $relation) {
                if ($record->{$relation}()->onlyTrashed()->exists()) {
                    return $relation;
                }
            }
        }

        return null;
    }

    private function label(string $type, Model $r): string
    {
        return match ($type) {
            'product', 'brand', 'category', 'zone' => (string) $r->name,
            'variant' => trim($r->name.' ('.$r->sku.')').($r->product ? ' of '.$r->product->name : ''),
            'image' => 'Photo of '.($r->product->name ?? 'a product').($r->alt ? ": {$r->alt}" : ''),
            'discount' => $r->code.' ('.$r->name.')',
            'campaign' => (string) $r->subject,
            'page' => $r->title.' (/info/'.$r->slug.')',
            'review' => $r->rating.'★ review'.($r->title ? ": {$r->title}" : '').' on '.($r->product->name ?? 'a product'),
            'note' => Str::limit((string) $r->body, 80),
            default => (string) $r->getKey(),
        };
    }

    /** @param  array<int, string>  $ids
     * @return array<string, string> */
    private function deletedBy(array $ids): array
    {
        return AuditLog::query()->whereIn('subject_id', $ids)->where('action', 'like', '%.deleted')->with('user:id,name')->latest('created_at')->get()
            ->unique('subject_id')->mapWithKeys(fn (AuditLog $a) => [$a->subject_id => $a->user?->name ?? 'Unknown'])->all();
    }
}
