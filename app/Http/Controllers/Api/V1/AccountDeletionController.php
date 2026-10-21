<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** A customer asking for their account to be deleted. Staff decide; nothing is erased from here. */
class AccountDeletionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->pending($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->isStaff()) {
            return response()->json(['message' => 'Staff accounts are removed by an administrator, not from here.', 'code' => 'staff_account'], 403);
        }
        $data = $request->validate(['password' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:500']]);
        // Asking again for the password makes sure it is the account owner, not someone at an unlocked device.
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['That password is not correct.']]);
        }

        $existing = $this->pending($request);
        $created = $existing ?? $user->deletionRequests()->create([
            'status' => AccountDeletionRequest::PENDING,
            'reason' => ($data['reason'] ?? null) ? trim(strip_tags($data['reason'])) ?: null : null,
            'requested_at' => now(),
        ]);

        return response()->json(['data' => $this->present($created)], $existing ? 200 : 201);
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->pending($request)?->update(['status' => AccountDeletionRequest::CANCELLED, 'processed_at' => now()]);

        return response()->json(null, 204);
    }

    private function pending(Request $request): ?AccountDeletionRequest
    {
        return $request->user()->deletionRequests()->where('status', AccountDeletionRequest::PENDING)->latest('requested_at')->first();
    }

    /** @return array<string, mixed>|null */
    private function present(?AccountDeletionRequest $r): ?array
    {
        return $r ? ['id' => $r->id, 'status' => $r->status, 'requested_at' => $r->requested_at->toIso8601String()] : null;
    }
}
