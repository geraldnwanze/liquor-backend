<?php

/*
 | Payment gateways. Secrets come only from the environment and are never logged or sent to the storefront.
 | Use each gateway's TEST keys outside production.
 |
 | Webhook URLs to register in each dashboard (HTTPS, publicly reachable):
 |   Paystack:    {APP_URL}/api/v1/webhooks/paystack     (signature = HMAC-SHA512 of the body with the secret key)
 |   Flutterwave: {APP_URL}/api/v1/webhooks/flutterwave  (set a secret hash in the dashboard; it must equal FLUTTERWAVE_SECRET_HASH)
 */
return [
    'currency' => 'NGN',

    // Gateways shown at checkout when they have credentials. Staff can narrow this at runtime (settings: payments.enabled_gateways).
    'enabled' => ['paystack', 'flutterwave'],

    'gateways' => [
        'paystack' => [
            'label' => 'Paystack',
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
        ],
        'flutterwave' => [
            'label' => 'Flutterwave',
            'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            // The "secret hash" you set in the Flutterwave dashboard webhook settings.
            'secret_hash' => env('FLUTTERWAVE_SECRET_HASH'),
            // Flutterwave rejects refunds below this amount (kobo).
            'min_refund_kobo' => 10_000,
        ],
        // Local development simulator. Refuses to run in production.
        'fake' => [
            'label' => 'Test payment (development only)',
            'enabled' => (bool) env('PAYMENTS_FAKE_GATEWAY', false),
        ],
    ],

    // Pending payments are re-checked with the gateway by `payments:reconcile`.
    'reconcile' => [
        'min_age_minutes' => 3,
        'give_up_after_hours' => 24,
    ],

    // Do not hit a gateway for the same payment more often than this when a customer's browser polls.
    'verify_min_interval_seconds' => 3,

    'http_timeout_seconds' => 15,
];
