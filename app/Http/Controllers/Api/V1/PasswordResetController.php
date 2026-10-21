<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    /** Always answers the same way, so this cannot be used to discover which emails have accounts. */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'If an account exists for that email, we\'ve sent a link to reset the password.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);

        $status = Password::reset($data + ['password_confirmation' => $request->input('password_confirmation')], function (User $user, string $password): void {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete(); // a reset signs out every existing session
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'This reset link is invalid or has expired. Request a new one.', 'code' => 'invalid_reset_token'], 422);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }
}
