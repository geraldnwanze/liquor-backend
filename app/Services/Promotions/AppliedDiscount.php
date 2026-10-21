<?php

namespace App\Services\Promotions;

use App\Models\Discount;

/** The outcome of a successful code check. All amounts are integer kobo. */
final readonly class AppliedDiscount
{
    /**
     * @param  int  $amountKobo  taken off the items (zero for free delivery)
     * @param  ?int  $deliveryWaivedKobo  delivery fee removed; null when the delivery area is not known yet
     */
    public function __construct(public Discount $discount, public int $amountKobo, public ?int $deliveryWaivedKobo) {}
}
