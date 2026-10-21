<?php

use App\Support\NigerianPhone;

it('normalises common Nigerian formats to E.164', function (string $input) {
    expect(NigerianPhone::normalize($input))->toBe('+2348031234567');
})->with(['08031234567', '8031234567', '2348031234567', '+234 803 123 4567', '0803-123-4567']);

it('rejects invalid numbers', function (string $input) {
    expect(NigerianPhone::normalize($input))->toBeNull();
})->with(['12345', '0603123456', '+44 7911 123456', '']);
