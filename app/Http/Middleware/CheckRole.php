<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles  Dozwolone role (np. admin, manager)
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Nie jesteś zalogowany.'], 401);
            }
            return redirect()->route('login');
        }

        // Developer ma dostęp do wszystkiego (ukryta rola)
        if ($user->isDeveloper()) {
            return $next($request);
        }

        if (!in_array($user->role, $roles)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Brak uprawnień do tej sekcji.'], 403);
            }
            
            return redirect()->route('dashboard')->with('error', 'Nie masz uprawnień do tej sekcji.');
        }

        return $next($request);
    }
}
