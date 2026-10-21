<?php

namespace App\Services\Checkout;

final readonly class CheckoutData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
        public string $state,
        public string $city,
        public string $address,
        public ?string $instructions,
        public bool $ageConfirmed,
        /** The code applied to the cart, taken from the cart itself and never from the request body. */
        public ?string $discountCode = null,
    ) {}

    public function withDiscountCode(?string $code): self
    {
        return new self($this->name, $this->email, $this->phone, $this->state, $this->city, $this->address, $this->instructions, $this->ageConfirmed, $code);
    }

    /** Fingerprint used to detect a reused Idempotency-Key with a different request body. */
    public function hash(): string
    {
        return hash('sha256', json_encode([
            $this->name, mb_strtolower($this->email), $this->phone, mb_strtolower($this->state), mb_strtolower($this->city),
            $this->address, $this->instructions, $this->ageConfirmed, $this->discountCode,
        ], JSON_THROW_ON_ERROR));
    }
}
