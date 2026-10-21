<?php

namespace App\Enums;

enum DiscountType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case FreeDelivery = 'free_delivery';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage off',
            self::Fixed => 'Fixed amount off',
            self::FreeDelivery => 'Free delivery',
        };
    }
}
