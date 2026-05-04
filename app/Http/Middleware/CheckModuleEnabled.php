<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleEnabled
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        // For now, all modules are enabled
        // Later this can check config or database for enabled modules
        return $next($request);
    }
}
