<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A suspended or deactivated account is signed out on the spot, even if a token somehow survived. */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->is_active) {
            $user->tokens()->delete();

            return response()->json(['message' => 'This account is not active.', 'code' => 'account_inactive'], 401);
        }

        return $next($request);
    }
}
