<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $permission  Kod uprawnienia (np. tasks_view, clients_manage)
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Nie jesteś zalogowany.'], 401);
            }
            return redirect()->route('login');
        }

        if (!$user->hasPermission($permission)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Brak uprawnień do tej akcji.'], 403);
            }
            
            return redirect()->back()->with('error', 'Nie masz uprawnień do tej akcji.');
        }

        return $next($request);
    }
}
