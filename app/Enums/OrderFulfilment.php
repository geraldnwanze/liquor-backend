<?php

namespace App\Enums;

enum OrderFulfilment: string
{
    case Unfulfilled = 'unfulfilled';
    case Processing = 'processing';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';

    /** The only moves staff can make from here. Delivered is final: later problems are handled as refunds. */
    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Unfulfilled => [self::Processing],
            self::Processing => [self::OutForDelivery],
            self::OutForDelivery => [self::Delivered, self::DeliveryFailed],
            self::DeliveryFailed => [self::OutForDelivery, self::Processing],
            self::Delivered => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    /** Statuses at which a paid order has not left the premises yet, so it can still be cancelled and restocked. */
    public function isCancellable(): bool
    {
        return $this === self::Unfulfilled || $this === self::Processing;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unfulfilled => 'Awaiting preparation',
            self::Processing => 'Preparing your order',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::DeliveryFailed => 'Delivery attempt unsuccessful',
        };
    }
}
