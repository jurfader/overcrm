<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blokuje akcje ktore mutuja stan GLOBALNY (pliki na dysku, shared cache)
 * gdy aplikacja dziala w trybie demo. Per-session DB jest izolowane wiec
 * Eloquent zapisy sa OK — ale marketplace install/update/uninstall zapisuje
 * do modules/ ktore widza wszyscy demo userzy.
 *
 * Uzycie: middleware('not-demo') na trasie.
 */
class BlockInDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('demo.enabled')) {
            return $next($request);
        }

        $msg = 'Ta akcja jest niedostepna w trybie demo.';

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => $msg], 403);
        }

        return back()->with('error', $msg);
    }
}
