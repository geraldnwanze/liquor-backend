<?php

namespace App\Services\Cart;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;

/** One priced, validated cart line. Computed fresh on every read; never persisted. */
final readonly class CartLine
{
    /**
     * @param  list<string>  $issues  unavailable | out_of_stock | insufficient_stock | over_line_limit
     */
    public function __construct(
        public CartItem $item,
        public ProductVariant $variant,
        public Product $product,
        public int $quantity,
        public int $unitPriceKobo,
        public int $lineTotalKobo,
        public int $maxQuantity,
        public array $issues,
        public ?int $priceChangedFromKobo,
    ) {}

    public function isPayable(): bool
    {
        return $this->issues === [];
    }
}
