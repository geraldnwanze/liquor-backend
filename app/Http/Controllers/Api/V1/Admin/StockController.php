<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StockReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StockAdjustmentRequest;
use App\Http\Resources\Admin\AdminVariantResource;
use App\Models\ProductVariant;
use App\Services\Administration\AuditLogger;
use App\Services\Inventory\InsufficientStockException;
use App\Services\Inventory\StockAdjuster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockController extends Controller
{
    public function __construct(private StockAdjuster $stock, private AuditLogger $audit) {}

    public function adjust(StockAdjustmentRequest $request, ProductVariant $variant): JsonResponse
    {
        $data = $request->validated();

        try {
            $movement = $this->stock->adjust(
                $variant, (int) $data['delta'], StockReason::from($data['reason']), $request->user(), $data['note'] ?? null,
            );
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'insufficient_stock'], 422);
        }

        $this->audit->record('stock.adjusted', $variant, ['delta' => $movement->delta, 'reason' => $data['reason']]);

        return response()->json(['data' => [
            'variant' => new AdminVariantResource($variant),
            'movement' => $movement->only(['id', 'delta', 'on_hand_after', 'reason', 'note', 'created_at']),
        ]]);
    }

    public function movements(ProductVariant $variant): JsonResponse
    {
        $page = $variant->movements()->with('user:id,name')->latest('id')->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($m) => [
                'id' => $m->id,
                'delta' => $m->delta,
                'on_hand_after' => $m->on_hand_after,
                'reason' => $m->reason->value,
                'note' => $m->note,
                'by' => $m->user?->only(['id', 'name']),
                'created_at' => $m->created_at->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function lowStock(): AnonymousResourceCollection
    {
        $variants = ProductVariant::query()->with('product:id,name,slug')
            ->where('is_active', true)
            ->whereRaw('(on_hand - reserved) <= low_stock_threshold')
            ->orderByRaw('(on_hand - reserved)')
            ->paginate(50);

        return AdminVariantResource::collection($variants);
    }
}
