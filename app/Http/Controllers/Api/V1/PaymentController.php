<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentState;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderAccess;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentGatewayManager $gateways,
        private PaymentService $payments,
        private PaymentVerifier $verifier,
        private OrderAccess $access,
    ) {}

    /** Payment methods open right now (enabled by staff and configured with credentials). */
    public function gateways(): JsonResponse
    {
        return response()->json(['data' => collect($this->gateways->available())->map(fn ($g) => ['id' => $g->name(), 'label' => $g->label()])->values()]);
    }

    public function initialize(Request $request, Order $order): JsonResponse
    {
        abort_unless($this->access->owns($request, $order), 404);
        $data = $request->validate(['gateway' => ['required', 'string', 'max:20']]);

        $payment = $this->payments->initialize($order, $data['gateway']);

        return (new PaymentResource($payment->load('order')))->response()->setStatusCode(201);
    }

    /**
     * The customer's browser comes back from the gateway (or polls). Whatever the redirect said is irrelevant:
     * we ask the gateway ourselves. Calls to the gateway are spaced out so polling cannot hammer it.
     */
    public function verify(Request $request, string $reference): PaymentResource
    {
        $payment = Payment::with('order')->where('reference', $reference)->first();
        abort_unless($payment && $this->access->owns($request, $payment->order), 404);

        // Pending, failed and abandoned payments can still change (the customer may retry on the gateway's page).
        $canChange = in_array($payment->state, [PaymentState::Pending, PaymentState::Failed, PaymentState::Abandoned], true);
        $recentlyAsked = $payment->last_verified_at?->gt(now()->subSeconds((int) config('payments.verify_min_interval_seconds'))) ?? false;
        if ($canChange && ! $recentlyAsked) {
            $payment = $this->verifier->verify($payment, 'verify');
        }

        return new PaymentResource($payment->load('order'));
    }
}
