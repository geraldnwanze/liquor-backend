<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StockReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\VariantRequest;
use App\Http\Resources\Admin\AdminVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Administration\AuditLogger;
use App\Services\Inventory\StockAdjuster;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class VariantController extends Controller
{
    public function __construct(private AuditLogger $audit, private StockAdjuster $stock) {}

    public function store(VariantRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();
        $initialStock = (int) ($data['initial_stock'] ?? 0);
        unset($data['initial_stock']);

        $variant = DB::transaction(function () use ($product, $data, $initialStock, $request) {
            $variant = $product->variants()->create($data);
            if ($initialStock > 0) {
                $this->stock->adjust($variant, $initialStock, StockReason::Initial, $request->user());
            }

            return $variant->refresh();
        });

        $this->audit->record('variant.created', $variant, ['sku' => $variant->sku, 'product_id' => $product->id]);

        return (new AdminVariantResource($variant))->response()->setStatusCode(201);
    }

    public function update(VariantRequest $request, ProductVariant $variant): AdminVariantResource
    {
        $variant->fill($request->validated())->save();
        $this->audit->record('variant.updated', $variant, ['changed' => array_keys($variant->getChanges())]);

        return new AdminVariantResource($variant);
    }

    public function destroy(ProductVariant $variant): JsonResponse
    {
        if ($variant->on_hand > 0 || $variant->reserved > 0) {
            return response()->json([
                'message' => 'This variant still has stock. Deactivate it instead, or adjust stock to zero first.',
                'code' => 'variant_has_stock',
            ], 409);
        }

        $this->audit->record('variant.deleted', $variant, ['sku' => $variant->sku]);
        $variant->delete();

        return response()->json(null, 204);
    }
}
