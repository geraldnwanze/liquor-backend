<?php

namespace App\Enums;

enum DiscountScope: string
{
    /** The whole cart. */
    case Order = 'order';
    case Products = 'products';
    case Categories = 'categories';
}
