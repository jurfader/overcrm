<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorVerified
{
    /**
     * Sprawdź czy użytkownik z włączonym 2FA potwierdził tożsamość.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user &&
            $user->two_factor_enabled &&
            !session('two_factor_verified') &&
            !$request->routeIs('two-factor.challenge', 'two-factor.verify', 'logout')
        ) {
            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
