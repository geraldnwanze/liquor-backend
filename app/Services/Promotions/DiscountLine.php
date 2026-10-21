<?php

namespace App\Services\Promotions;

/** One cart line as the discount rules see it: what it is, and what it costs now. */
final readonly class DiscountLine
{
    public function __construct(public string $productId, public ?string $categoryId, public int $lineTotalKobo) {}
}
