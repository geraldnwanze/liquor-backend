<?php

namespace App\Support;

/** Integer-kobo money helpers. No floating-point arithmetic is used for amounts. */
final class Money
{
    /** Integer kobo -> decimal naira string for gateways that take major units, e.g. 150050 -> "1500.50". No floats. */
    public static function toNairaString(int $kobo): string
    {
        return intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * A gateway's major-unit amount (int, float or numeric string) -> integer kobo. Parsed from its decimal text so
     * 1500.1 becomes 150010 exactly; anything with more than two decimals is rounded to the nearest kobo.
     */
    public static function fromNaira(int|float|string $naira): int
    {
        $text = is_float($naira) ? rtrim(rtrim(number_format($naira, 6, '.', ''), '0'), '.') : (string) $naira;
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($text), $m)) {
            throw new \InvalidArgumentException('Not a decimal amount: '.$text);
        }
        $fraction = str_pad($m[3] ?? '', 3, '0');
        $kobo = (int) $m[2] * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return $m[1] === '-' ? -$kobo : $kobo;
    }

    /** @return array{kobo: int, formatted: string, currency: string} */
    public static function toArray(int $kobo): array
    {
        return ['kobo' => $kobo, 'formatted' => self::format($kobo), 'currency' => 'NGN'];
    }

    public static function format(int $kobo, string $symbol = '₦'): string
    {
        $naira = intdiv($kobo, 100);
        $remainder = $kobo % 100;
        $text = $symbol.number_format($naira);

        return $remainder === 0 ? $text : $text.'.'.str_pad((string) $remainder, 2, '0', STR_PAD_LEFT);
    }
}
