<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\RefundService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /** Payments for staff, newest first. `?state=review` is the queue of money that needs a human decision. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['state' => ['nullable', 'in:pending,succeeded,failed,abandoned,review']]);

        $page = Payment::query()->with('order:id,reference,email,total_kobo')
            ->when($request->query('state'), fn ($q, $state) => $q->where('state', $state))
            ->latest('id')->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Payment $p) => [
                'reference' => $p->reference,
                'gateway' => $p->gateway,
                'state' => $p->state->value,
                'review_reason' => $p->review_reason,
                'amount' => Money::toArray($p->amount_kobo),
                'refunded' => Money::toArray($p->refunded_kobo),
                'order_reference' => $p->order->reference,
                'customer_email' => $p->order->email,
                'created_at' => $p->created_at->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** Needs the `orders.refund` permission and a unique Idempotency-Key per intended refund. */
    public function refund(Request $request, RefundService $refunds, Payment $payment): JsonResponse
    {
        $data = $request->validate([
            'amount_kobo' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(preg_match('/^[A-Za-z0-9_\-]{16,64}$/', $key) === 1, 422, 'An Idempotency-Key header (16-64 letters, digits, - or _) is required.');

        $refund = $refunds->request($payment, $data['amount_kobo'] ?? null, strip_tags($data['reason']), $request->user(), $key);

        return response()->json(['data' => [
            'id' => $refund->id,
            'state' => $refund->state,
            'amount' => Money::toArray($refund->amount_kobo),
            'payment_refunded' => Money::toArray($payment->refresh()->refunded_kobo),
        ]], $refund->wasRecentlyCreated ? 201 : 200);
    }
}
