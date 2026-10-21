<?php

namespace App\Enums;

enum PaymentState: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    /** The gateway reports money received, but it cannot be fulfilled automatically (mismatch, duplicate, late). */
    case Review = 'review';

    /** A payment that has settled and must never be changed by a later verification. */
    public function isSettled(): bool
    {
        return $this === self::Succeeded || $this === self::Review;
    }
}
