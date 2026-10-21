<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\CommerceException;
use App\Services\Administration\SettingsRepository;
use App\Services\Payments\Gateways\FakeGateway;
use App\Services\Payments\Gateways\FlutterwaveGateway;
use App\Services\Payments\Gateways\PaystackGateway;

class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $gateways;

    public function __construct(private SettingsRepository $settings)
    {
        $this->gateways = [
            'paystack' => new PaystackGateway,
            'flutterwave' => new FlutterwaveGateway,
            'fake' => new FakeGateway,
        ];
    }

    /** @throws CommerceException for an unknown gateway name */
    public function get(string $name): PaymentGateway
    {
        return $this->gateways[$name] ?? throw new CommerceException('gateway_unavailable', 'That payment method is not available.', 422);
    }

    /**
     * Gateways a customer may use now: switched on (config, overridable by staff in settings) AND configured with
     * credentials. The simulator is offered only outside production.
     *
     * @return array<string, PaymentGateway>
     */
    public function available(): array
    {
        $enabled = (array) $this->settings->get('payments', 'enabled_gateways', config('payments.enabled'));
        $enabled[] = 'fake';

        return array_filter(
            $this->gateways,
            fn (PaymentGateway $g, string $name) => in_array($name, $enabled, true) && $g->isConfigured(),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public function isAvailable(string $name): bool
    {
        return isset($this->available()[$name]);
    }
}
