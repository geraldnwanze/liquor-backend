<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\Gateways\FakeGateway;
use App\Services\Payments\PaymentVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Local development only (routes are not registered in production). Stands in for the customer on a gateway page. */
class DevPaymentController extends Controller
{
    public function simulate(Request $request, string $reference, FakeGateway $gateway, PaymentVerifier $verifier): JsonResponse
    {
        abort_if(app()->isProduction(), 404);
        $data = $request->validate(['outcome' => ['required', 'in:success,failed,abandoned']]);
        $payment = Payment::where('reference', $reference)->where('gateway', 'fake')->firstOrFail();

        $gateway->simulate($reference, $data['outcome']);

        return response()->json(['data' => ['reference' => $payment->reference, 'outcome' => $data['outcome']]]);
    }
}
