<?php

namespace App\Http\Resources;

use App\Enums\PaymentState;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** What the paying customer may see. Gateway ids, review reasons and attempt logs stay internal. @mixin Payment */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->order;

        return [
            'reference' => $this->reference,
            'gateway' => $this->gateway,
            'state' => $this->state->value,
            'amount' => Money::toArray($this->amount_kobo),
            // Only a pending payment has somewhere left to go.
            'authorization_url' => $this->when($this->state === PaymentState::Pending, $this->authorization_url),
            'message' => match ($this->state) {
                PaymentState::Pending => 'Waiting for the payment to be confirmed.',
                PaymentState::Succeeded => 'Payment received. Thank you!',
                PaymentState::Failed => 'The payment did not go through. You have not been charged for this attempt.',
                PaymentState::Abandoned => 'The payment was not completed.',
                PaymentState::Review => 'We received your payment but need to check it before confirming the order. Please do not pay again: we will contact you.',
            },
            'order' => ['reference' => $order->reference, 'status' => $order->status->value, 'payment_status' => $order->payment_status->value],
        ];
    }
}
