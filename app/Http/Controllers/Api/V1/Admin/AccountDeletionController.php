<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use App\Notifications\AccountDeletionNotification;
use App\Services\Administration\AuditLogger;
use App\Services\Customers\CustomerEraser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class AccountDeletionController extends Controller
{
    public function __construct(private CustomerEraser $eraser, private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:pending,completed,rejected,cancelled']])['status'] ?? AccountDeletionRequest::PENDING;

        $rows = AccountDeletionRequest::query()->where('status', $status)->with('user')->latest('requested_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (AccountDeletionRequest $r) => [
            'id' => $r->id,
            'status' => $r->status,
            'requested_at' => $r->requested_at->toIso8601String(),
            'processed_at' => $r->processed_at?->toIso8601String(),
            'reason' => $r->reason,
            'staff_note' => $r->staff_note,
            'customer' => $r->user->anonymized_at ? ['id' => $r->user->id, 'name' => 'Deleted customer', 'email' => null] : $r->user->only(['id', 'name', 'email']),
            'paid_orders' => $r->user->orders()->where('status', OrderStatus::Paid)->count(),
            'has_open_orders' => $this->eraser->hasOpenOrders($r->user),
        ])->values()]);
    }

    /** Erases the customer's personal data (see CustomerEraser). Refused while they have orders still in motion. */
    public function complete(Request $request, AccountDeletionRequest $deletionRequest): JsonResponse
    {
        if ($deletionRequest->status !== AccountDeletionRequest::PENDING) {
            return response()->json(['message' => 'This request has already been dealt with.', 'code' => 'request_closed'], 409);
        }
        $user = $deletionRequest->user;
        if ($this->eraser->hasOpenOrders($user)) {
            return response()->json(['message' => 'This customer still has an order that is unpaid or not yet delivered. Finish it first, or decline the request for now.', 'code' => 'open_orders'], 409);
        }

        $email = $this->eraser->erase($user);
        $deletionRequest->update(['status' => AccountDeletionRequest::COMPLETED, 'processed_at' => now(), 'processed_by' => $request->user()->id]);
        $this->audit->record('customer.erased', $user, ['request' => $deletionRequest->id]);
        Notification::route('mail', $email)->notify(new AccountDeletionNotification('completed'));

        return response()->json(['data' => ['id' => $deletionRequest->id, 'status' => $deletionRequest->status]]);
    }

    public function reject(Request $request, AccountDeletionRequest $deletionRequest): JsonResponse
    {
        if ($deletionRequest->status !== AccountDeletionRequest::PENDING) {
            return response()->json(['message' => 'This request has already been dealt with.', 'code' => 'request_closed'], 409);
        }
        $note = trim(strip_tags((string) $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']])['note']));

        $deletionRequest->update(['status' => AccountDeletionRequest::REJECTED, 'processed_at' => now(), 'processed_by' => $request->user()->id, 'staff_note' => $note]);
        $this->audit->record('customer.erasure_declined', $deletionRequest->user, ['request' => $deletionRequest->id]);
        Notification::route('mail', $deletionRequest->user->email)->notify(new AccountDeletionNotification('rejected', $note));

        return response()->json(['data' => ['id' => $deletionRequest->id, 'status' => $deletionRequest->status]]);
    }
}
