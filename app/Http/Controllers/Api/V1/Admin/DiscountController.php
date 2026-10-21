<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RedemptionState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DiscountRequest;
use App\Http\Resources\Admin\AdminDiscountResource;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
use App\Services\Administration\AuditLogger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class DiscountController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,scheduled,expired,disabled'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $discounts = Discount::query()
            ->withCount(['redemptions as uses_count' => fn ($q) => $q->whereIn('state', [RedemptionState::Reserved, RedemptionState::Redeemed])])
            ->when($f['q'] ?? null, function ($q, string $term) {
                $like = '%'.addcslashes(trim($term), '%_\\').'%';
                $q->where(fn ($w) => $w->where('code', 'like', strtoupper($like))->orWhere('name', 'like', $like));
            })
            ->when($f['status'] ?? null, function ($q, string $status) {
                match ($status) {
                    'disabled' => $q->where('is_active', false),
                    'scheduled' => $q->where('is_active', true)->where('starts_at', '>', now()),
                    'expired' => $q->where('is_active', true)->where('ends_at', '<', now()),
                    'active' => $q->where('is_active', true)->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                        ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now())),
                };
            })
            ->latest('id')->paginate($f['per_page'] ?? 25)->withQueryString();

        return AdminDiscountResource::collection($discounts);
    }

    /**
     * What a discount can be aimed at. Promotions staff don't have catalogue access, so this gives them just
     * enough to pick products (type-ahead by name or SKU) and categories, and nothing else.
     */
    public function targets(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $q = trim($request->string('q')->toString());

        $products = $q === '' ? collect() : Product::query()
            ->where(function ($w) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $w->where('name', 'like', $like)->orWhereHas('variants', fn ($v) => $v->where('sku', 'like', $like));
            })
            ->orderBy('name')->limit(10)->get(['id', 'name', 'slug']);

        return response()->json(['data' => [
            'products' => $products->values(),
            'categories' => Category::query()->orderBy('parent_id')->orderBy('sort_order')->get(['id', 'name', 'parent_id']),
        ]]);
    }

    public function show(Discount $discount): JsonResponse
    {
        $discount->loadCount(['redemptions as uses_count' => fn ($q) => $q->whereIn('state', [RedemptionState::Reserved, RedemptionState::Redeemed])])
            ->load(['products:id,name,slug', 'categories:id,name,slug']);
        $discount->given_kobo = (int) $discount->redemptions()->where('state', RedemptionState::Redeemed)->sum('amount_kobo');
        $waived = (int) $discount->redemptions()->where('state', RedemptionState::Redeemed)->sum('delivery_waived_kobo');

        $recent = $discount->redemptions()->with('order:id,reference,total_kobo')->latest('id')->limit(20)->get()->map(fn ($r) => [
            'order' => $r->order?->reference,
            'state' => $r->state->value,
            'amount' => Money::toArray($r->amount_kobo),
            'delivery_waived' => Money::toArray($r->delivery_waived_kobo),
            'email' => $r->email,
            'at' => $r->created_at->toIso8601String(),
        ]);

        return response()->json(['data' => [...(new AdminDiscountResource($discount))->resolve(), 'delivery_waived' => Money::toArray($waived), 'recent_redemptions' => $recent]]);
    }

    public function store(DiscountRequest $request): JsonResponse
    {
        $discount = DB::transaction(function () use ($request): Discount {
            $discount = Discount::create([...$request->discountAttributes(), 'created_by' => $request->user()->id]);
            $this->syncTargets($discount, $request);

            return $discount;
        });
        $this->audit->record('discount.created', $discount, ['code' => $discount->code, 'type' => $discount->type->value]);

        return (new AdminDiscountResource($discount->refresh()->load(['products:id,name,slug', 'categories:id,name,slug'])))->response()->setStatusCode(201);
    }

    public function update(DiscountRequest $request, Discount $discount): AdminDiscountResource
    {
        DB::transaction(function () use ($request, $discount): void {
            $discount->fill($request->discountAttributes())->save();
            $this->syncTargets($discount, $request);
        });
        $this->audit->record('discount.updated', $discount, ['code' => $discount->code, 'changed' => array_keys($discount->getChanges())]);

        return new AdminDiscountResource($discount->refresh()->load(['products:id,name,slug', 'categories:id,name,slug']));
    }

    /** A discount that has been used is part of order history: switch it off instead of deleting it. */
    public function destroy(Discount $discount): JsonResponse
    {
        if ($discount->redemptions()->exists()) {
            return response()->json([
                'message' => 'This code has been used on orders, so it can\'t be deleted. Switch it off instead.',
                'code' => 'discount_has_history',
            ], 409);
        }
        $this->audit->record('discount.deleted', $discount, ['code' => $discount->code]);
        $discount->delete();

        return response()->json(null, 204);
    }

    private function syncTargets(Discount $discount, DiscountRequest $request): void
    {
        $discount->products()->sync($request->input('scope') === 'products' ? $request->input('product_ids', []) : []);
        $discount->categories()->sync($request->input('scope') === 'categories' ? $request->input('category_ids', []) : []);
    }
}
