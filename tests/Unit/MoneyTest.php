<?php

use App\Support\Money;

it('formats integer kobo as the decimal naira string some gateways expect', function (int $kobo, string $expected) {
    expect(Money::toNairaString($kobo))->toBe($expected);
})->with([[0, '0.00'], [5, '0.05'], [100, '1.00'], [150050, '1500.50'], [2500000, '25000.00'], [99999999, '999999.99']]);

it('parses a gateway amount back to exact kobo without float error', function (int|float|string $naira, int $kobo) {
    expect(Money::fromNaira($naira))->toBe($kobo);
})->with([
    [3000, 300000], ['7500', 750000], [1500.5, 150050], ['1500.10', 150010], [0.1 + 0.2, 30], [19.99, 1999], [1234.567, 123457], [0, 0],
]);

it('round-trips every kobo value through the decimal text', function () {
    foreach ([1, 7, 99, 100, 101, 12345, 987654321] as $kobo) {
        expect(Money::fromNaira(Money::toNairaString($kobo)))->toBe($kobo);
    }
});

it('rejects values that are not amounts', function () {
    expect(fn () => Money::fromNaira('12abc'))->toThrow(InvalidArgumentException::class);
});
