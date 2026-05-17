<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo mode middleware.
 *
 * Gdy config('demo.enabled') true:
 *  1. Czyta cookie 'demo_session' (UUID), generuje go gdy brak.
 *  2. Przelacza connection 'sqlite' na storage/app/demo/{uuid}.sqlite.
 *  3. Jezeli plik nie istnieje, kopiuje z _template.sqlite (zaszczepiony przez
 *     'php artisan demo:build-template' przy pierwszym uruchomieniu).
 *  4. Auto-loguje uzytkownika z config('demo.auto_login_email') (admin w template).
 *
 * Czyszczenie starych plikow: 'php artisan demo:cleanup' — scheduler co godzine.
 */
class EnableDemoMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('demo.enabled')) {
            return $next($request);
        }

        $sessionId = $this->resolveSessionId($request);
        $dbPath = $this->ensureSessionDatabase($sessionId);

        // Przelacz default connection na per-session sqlite
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $dbPath,
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        // Auto-login jezeli nie zalogowany
        if (!Auth::check()) {
            $email = config('demo.auto_login_email');
            $user = DB::table('users')->where('email', $email)->first();
            if ($user) {
                Auth::loginUsingId($user->id);
            }
        }

        $response = $next($request);

        // Dolacz/odswiez cookie zeby przegladarka pamietala session id (7 dni;
        // plik DB i tak zniknie po 24h przez cleanup, ale cookie zostaje by user
        // zorientowal sie ze nowa sesja zaczela sie czysto)
        $response->headers->setCookie(cookie(
            config('demo.cookie'),
            $sessionId,
            60 * 24 * 7, // 7 dni
            '/',
            null,
            $request->isSecure(),
            true, // httpOnly
            false,
            'lax'
        ));

        return $response;
    }

    protected function resolveSessionId(Request $request): string
    {
        $existing = $request->cookie(config('demo.cookie'));
        if ($existing && preg_match('/^[a-f0-9-]{8,64}$/', $existing)) {
            return $existing;
        }
        return Str::uuid()->toString();
    }

    protected function ensureSessionDatabase(string $sessionId): string
    {
        $dir = config('demo.path');
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $template = $dir . '/_template.sqlite';
        $sessionPath = $dir . '/' . $sessionId . '.sqlite';

        if (!File::exists($sessionPath)) {
            if (!File::exists($template)) {
                Log::warning('Demo template missing — run "php artisan demo:build-template"');
                // Fallback: pusty plik, app sie wywali na pierwszej query, ale lepiej
                // pokazac wyrazny blad niz wyciekac dane miedzy sesjami
                File::put($sessionPath, '');
            } else {
                File::copy($template, $sessionPath);
            }
        }

        // Touch — zeby cleanup nie kasowal aktywnej sesji
        touch($sessionPath);
        return $sessionPath;
    }
}
