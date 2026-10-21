<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\DeliveryZone;
use App\Services\Promotions\AppliedDiscount;

final readonly class CartSnapshot
{
    /** @param list<CartLine> $lines */
    public function __construct(
        public Cart $cart,
        public array $lines,
        public int $subtotalKobo,
        public int $itemCount,
        public ?DeliveryZone $zone,
        public ?int $deliveryFeeKobo,
        public ?AppliedDiscount $discount = null,
        /** @var array{code: string, message: string, details: array<string, mixed>}|null why the applied code does not currently work */
        public ?array $discountProblem = null,
    ) {}

    /** The delivery fee after any free-delivery code, or null while the address is unknown. */
    public function effectiveDeliveryFeeKobo(): ?int
    {
        return $this->deliveryFeeKobo === null ? null : max(0, $this->deliveryFeeKobo - ($this->discount?->deliveryWaivedKobo ?? 0));
    }

    public function hasIssues(): bool
    {
        foreach ($this->lines as $line) {
            if (! $line->isPayable()) {
                return true;
            }
        }

        return false;
    }
}
