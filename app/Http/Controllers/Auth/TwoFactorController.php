<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class TwoFactorController extends Controller
{
    /**
     * Pokaż stronę konfiguracji 2FA
     */
    public function setup(Request $request): Response
    {
        $user = $request->user();
        $google2fa = new Google2FA();

        // Wygeneruj secret jeśli nie ma
        if (!$user->two_factor_secret) {
            $secret = $google2fa->generateSecretKey();
            $user->update(['two_factor_secret' => $secret]);
        } else {
            $secret = $user->two_factor_secret;
        }

        // Wygeneruj QR code jako SVG
        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name', 'Planner'),
            $user->email,
            $secret,
        );

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrCodeSvg = $writer->writeString($qrCodeUrl);

        return Inertia::render('Auth/TwoFactorSetup', [
            'qrCodeSvg' => $qrCodeSvg,
            'secret' => $secret,
            'enabled' => (bool) $user->two_factor_enabled,
        ]);
    }

    /**
     * Włącz 2FA po weryfikacji kodu
     */
    public function enable(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $google2fa = new Google2FA();

        if (!$google2fa->verifyKey($user->two_factor_secret, $request->code)) {
            return back()->withErrors(['code' => 'Nieprawidłowy kod. Spróbuj ponownie.']);
        }

        // Wygeneruj kody zapasowe
        $recoveryCodes = collect(range(1, 8))->map(fn() => Str::random(10))->toArray();

        $user->update([
            'two_factor_enabled' => true,
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
        ]);

        // Zapisz w sesji że 2FA przeszło
        session(['two_factor_verified' => true]);

        return redirect()->route('two-factor.setup')
            ->with('success', 'Uwierzytelnianie dwuskładnikowe zostało włączone.')
            ->with('recovery_codes', $recoveryCodes);
    }

    /**
     * Wyłącz 2FA
     */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => 'required|current_password',
        ]);

        $request->user()->update([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ]);

        session()->forget('two_factor_verified');

        return redirect()->route('two-factor.setup')
            ->with('success', 'Uwierzytelnianie dwuskładnikowe zostało wyłączone.');
    }

    /**
     * Pokaż formularz weryfikacji 2FA (po logowaniu)
     */
    public function challenge(): Response
    {
        return Inertia::render('Auth/TwoFactorChallenge');
    }

    /**
     * Weryfikuj kod 2FA po logowaniu
     */
    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = $request->user();
        $google2fa = new Google2FA();
        $code = $request->code;

        // Sprawdź TOTP
        if (strlen($code) === 6 && $google2fa->verifyKey($user->two_factor_secret, $code)) {
            session(['two_factor_verified' => true]);
            return redirect()->intended(route('dashboard'));
        }

        // Sprawdź kod zapasowy
        if (strlen($code) === 10 && $user->two_factor_recovery_codes) {
            $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);
            $index = array_search($code, $codes);

            if ($index !== false) {
                unset($codes[$index]);
                $user->update([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
                ]);
                session(['two_factor_verified' => true]);
                return redirect()->intended(route('dashboard'));
            }
        }

        return back()->withErrors(['code' => 'Nieprawidłowy kod.']);
    }
}
