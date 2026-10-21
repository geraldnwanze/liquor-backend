<?php

namespace App\Services\Checkout;

use App\Contracts\AgeVerifier;
use App\Models\User;
use App\Services\Commerce\CommerceSettings;

class SelfDeclarationAgeVerifier implements AgeVerifier
{
    public function __construct(private CommerceSettings $settings) {}

    public function verify(?User $user, bool $declaredOfAge): AgeVerificationResult
    {
        $min = $this->settings->minimumAge();

        if (! $declaredOfAge) {
            return new AgeVerificationResult(false, 'self_declaration', "You must confirm you are at least {$min} years old.");
        }

        // A date of birth on the account can contradict the declaration, and then it wins.
        if ($user?->date_of_birth && $user->date_of_birth->age < $min) {
            return new AgeVerificationResult(false, 'account_date_of_birth', "You must be at least {$min} years old to buy alcohol.");
        }

        return new AgeVerificationResult(true, 'self_declaration');
    }
}
