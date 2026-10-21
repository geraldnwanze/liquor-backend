<?php

namespace App\Support;

/**
 * Normalises Nigerian mobile numbers to E.164 (+234XXXXXXXXXX).
 * Accepts 0803..., 803..., 234803..., +234803... with spaces/dashes.
 */
final class NigerianPhone
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (str_starts_with($digits, '234')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // Nigerian mobile numbers: 10 digits after the country code, starting 7, 8 or 9.
        return preg_match('/^[789]\d{9}$/', $digits) === 1 ? '+234'.$digits : null;
    }
}
