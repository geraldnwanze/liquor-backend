<?php

namespace App\Contracts;

use App\Models\User;
use App\Services\Checkout\AgeVerificationResult;

/**
 * Extension point for age checks. The default binding only records a self-declaration (and checks a date of birth
 * if the customer has one on file), which is NOT proof of age. Bind a stronger implementation (ID check, KYC
 * provider, delivery-time verification) here if the business or applicable regulation requires it.
 */
interface AgeVerifier
{
    public function verify(?User $user, bool $declaredOfAge): AgeVerificationResult;
}
