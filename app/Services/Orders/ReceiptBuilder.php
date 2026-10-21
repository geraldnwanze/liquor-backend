<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Exceptions\CommerceException;
use App\Models\Order;
use App\Services\Administration\BusinessLogo;
use App\Services\Administration\SettingsRepository;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Money;

/**
 * The data for a receipt/invoice. Business details come from staff-editable settings (group "business"), so nothing
 * about the company is hardcoded. No tax is computed or invented: amounts are exactly what the customer was charged.
 * Payment details are limited to the method and our reference; card data never exists on our side.
 */
class ReceiptBuilder
{
    public function __construct(private SettingsRepository $settings, private PaymentGatewayManager $gateways, private BusinessLogo $logo) {}

    /** @return array<string, mixed> */
    public function build(Order $order): array
    {
        if ($order->paid_at === null || ! in_array($order->status, [OrderStatus::Paid, OrderStatus::Cancelled], true)) {
            throw new CommerceException('receipt_unavailable', 'A receipt is available once the order has been paid.', 409);
        }

        $order->loadMissing('items', 'payments');
        $payment = $order->payments->where('state', PaymentState::Succeeded)->sortByDesc('id')->first();

        return [
            'number' => $order->reference,
            'issued_at' => $order->paid_at->toIso8601String(),
            'currency' => $order->currency,
            'business' => [
                'name' => $this->settings->get('business', 'name') ?: config('app.name'),
                'address' => $this->settings->get('business', 'address'),
                'phone' => $this->settings->get('business', 'phone'),
                'email' => $this->settings->get('business', 'email'),
                'registration_number' => $this->settings->get('business', 'registration_number'),
                'logo_url' => $this->logo->url(),
            ],
            'customer' => ['name' => $order->customer_name, 'email' => $order->email, 'phone' => $order->phone],
            'delivery' => ['address' => $order->delivery_address, 'city' => $order->delivery_city, 'state' => $order->delivery_state],
            'items' => $order->items->map(fn ($i) => [
                'description' => $i->product_name.' ('.$i->variant_name.')',
                'sku' => $i->sku,
                'quantity' => $i->quantity,
                'unit_price' => Money::toArray($i->unit_price_kobo),
                'line_total' => Money::toArray($i->line_total_kobo),
            ])->values(),
            'subtotal' => Money::toArray($order->subtotal_kobo),
            'discount' => Money::toArray($order->discount_kobo),
            'discount_code' => $order->discount_code,
            'delivery_fee' => Money::toArray($order->delivery_fee_kobo),
            'total' => Money::toArray($order->total_kobo),
            'payment' => $payment ? [
                'method' => $this->gateways->get($payment->gateway)->label().($payment->channel ? " ({$payment->channel})" : ''),
                'reference' => $payment->reference,
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ] : null,
            'refunded' => Money::toArray($payment?->refunded_kobo ?? 0),
            'status' => $order->status === OrderStatus::Cancelled ? 'cancelled' : 'paid',
            'footer' => $this->settings->get('business', 'receipt_footer'),
        ];
    }
}
