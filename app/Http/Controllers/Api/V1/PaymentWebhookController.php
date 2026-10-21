<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payments\WebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    /** Public endpoint called by the gateways. Authenticated by signature inside the processor, never by session. */
    public function handle(Request $request, WebhookProcessor $processor, string $gateway): JsonResponse
    {
        // Symfony lower-cases header names and stores each as a list.
        $headers = array_map(fn (array $values) => (string) ($values[0] ?? ''), $request->headers->all());

        // The raw body is needed byte-for-byte: Paystack signs it as received.
        $status = $processor->handle($gateway, $request->getContent(), $headers, $request->ip());

        return response()->json(['received' => $status === 200], $status);
    }
}
