<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

class ProfileController extends Controller
{
    /** Email is intentionally not editable here: changing it needs a verification flow. */
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        return new UserResource($user->load('roles'));
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['password' => $request->string('password')->toString()])->save();

        // Sign out every other device; keep the one that just proved it knows the old password.
        $current = $user->currentAccessToken();
        $others = $user->tokens();
        if ($current instanceof PersonalAccessToken) {
            $others->whereKeyNot($current->id);
        }
        $others->delete();

        return response()->json(['message' => 'Your password has been changed.']);
    }
}
