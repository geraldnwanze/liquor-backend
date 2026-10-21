<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const BAD_CREDENTIALS = 'These credentials do not match our records.';

    public function __construct(private CartService $carts) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());
        $this->carts->mergeGuestCartFromRequest($request, $user);

        return $this->tokenResponse($user, 201);
    }

    /** Customer sign-in. Staff accounts are refused here: they have their own portal and their own session. */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->authenticate($request);

        if ($user->isStaff()) {
            throw ValidationException::withMessages(['email' => ['Staff accounts sign in at the staff portal, not here.']]);
        }
        // Said only after the right password, so it cannot be used to find out who has an account.
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => ['This account has been suspended. Please contact us for help.']]);
        }

        $this->carts->mergeGuestCartFromRequest($request, $user);

        return $this->tokenResponse($user);
    }

    /**
     * Staff sign-in. Only accounts with a role succeed. Everyone else (customers included) gets exactly the same
     * answer as a wrong password, so this cannot be used to find out who works here.
     */
    public function staffLogin(LoginRequest $request): JsonResponse
    {
        $user = $this->authenticate($request);

        if (! $user->isStaff() || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => [self::BAD_CREDENTIALS]]);
        }

        return $this->tokenResponse($user);
    }

    private function authenticate(LoginRequest $request): User
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages(['email' => [self::BAD_CREDENTIALS]]);
        }

        return $user;
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles'));
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        $user->load('roles');
        // Staff tokens expire sooner than customer tokens.
        $expires = $user->isStaff() ? now()->addHours(8) : now()->addDays(30);

        return response()->json([
            'data' => [
                'token' => $user->createToken('api', ['*'], $expires)->plainTextToken,
                'expires_at' => $expires->toIso8601String(),
                'user' => new UserResource($user),
            ],
        ], $status);
    }
}
