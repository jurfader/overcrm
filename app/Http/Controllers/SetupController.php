<?php

namespace App\Http\Controllers;

use App\Services\BrandingService;
use App\Services\GusService;
use App\Services\LicenseService;
use App\Services\MarketplaceService;
use App\Services\SetupService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Kreator pierwszego uruchomienia. Dostępny tylko dla adminów/developerów —
 * pozostali użytkownicy widzą ekran "trwa konfiguracja" (Setup/Pending).
 *
 * Trasy /setup/* są whitelistowane w EnforceLicense, bo pierwszym krokiem
 * kreatora jest właśnie aktywacja licencji.
 */
class SetupController extends Controller
{
    public function __construct(protected SetupService $setup) {}

    public function show(Request $request)
    {
        if (! $this->isAdmin($request)) {
            return Inertia::render('Setup/Pending', [
                'brand' => brand(),
            ]);
        }

        return Inertia::render('Setup/Index', [
            'setup' => $this->setup->state(),
            // Lista modułów wymaga HTTP do license servera (do 8 s przy braku
            // połączenia) — ładowana dopiero gdy user wejdzie w krok "Moduły".
            'marketplace' => Inertia::optional(fn () => $this->setup->marketplaceState()),
        ]);
    }

    // ===================================================================
    // Krok 1 — licencja
    // ===================================================================

    public function activateLicense(Request $request, LicenseService $license)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'license_key' => 'required|string|min:8|max:50',
        ]);

        $result = $license->activate($data['license_key']);

        if ($result['success']) {
            $this->setup->markStep('license');
        }

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function refreshLicense(Request $request, LicenseService $license)
    {
        $this->authorizeAdmin($request);

        $result = $license->validate();

        if ($result['success']) {
            $this->setup->markStep('license');
        }

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    // ===================================================================
    // Krok 2 — dane firmy
    // ===================================================================

    public function saveCompany(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'company_name' => 'nullable|string|max:150',
            'company_nip' => 'nullable|string|max:15',
            'company_regon' => 'nullable|string|max:14',
            'company_address' => 'nullable|string|max:200',
            'company_city' => 'nullable|string|max:100',
            'company_postal' => 'nullable|string|max:10',
            'company_phone' => 'nullable|string|max:30',
            'company_email' => 'nullable|email|max:120',
            'company_bank_account' => 'nullable|string|max:40',
        ]);

        $this->setup->saveCompany($data);

        return back()->with('success', 'Dane firmy zapisane');
    }

    /**
     * Pobranie danych firmy z GUS po NIP. Zwraca JSON (formularz kreatora
     * uzupełnia pola bez przeładowania strony).
     */
    public function lookupNip(Request $request, GusService $gus)
    {
        $this->authorizeAdmin($request);

        $request->validate(['nip' => 'required|string|min:10|max:13']);

        $nip = preg_replace('/[^0-9]/', '', $request->input('nip'));

        if (strlen($nip) !== 10 || ! GusService::validateNip($nip)) {
            return response()->json([
                'success' => false,
                'message' => 'Nieprawidłowy NIP — sprawdź czy ma 10 cyfr i poprawną sumę kontrolną',
            ], 422);
        }

        $data = $gus->getByNip($nip);

        if (! $data || empty($data['name'])) {
            return response()->json([
                'success' => false,
                'message' => 'Nie znaleziono firmy o tym NIP w bazie GUS',
            ], 404);
        }

        $street = trim(($data['street'] ?? '').' '.($data['building_number'] ?? ''));
        if (! empty($data['apartment_number'])) {
            $street .= '/'.$data['apartment_number'];
        }

        return response()->json([
            'success' => true,
            'company' => [
                'company_name' => $data['name'],
                'company_nip' => $nip,
                'company_regon' => $data['regon'] ?? null,
                'company_address' => trim($street),
                'company_city' => $data['city'] ?? null,
                'company_postal' => $data['postal_code'] ?? null,
            ],
        ]);
    }

    // ===================================================================
    // Krok 3 — branding
    // ===================================================================

    public function saveBranding(Request $request, BrandingService $branding)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate(BrandingService::rules());

        $branding->update($data);
        $this->setup->markStep('branding');

        return back()->with('success', 'Wygląd zapisany');
    }

    public function uploadBrandingAsset(Request $request, BrandingService $branding)
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'asset' => 'required|in:'.implode(',', BrandingService::ASSETS),
            'file' => 'required|image|mimes:jpeg,png,gif,svg,webp,ico|max:2048',
        ]);

        $branding->uploadAsset($request->input('asset'), $request->file('file'));

        return back()->with('success', 'Plik przesłany');
    }

    public function removeBrandingAsset(Request $request, BrandingService $branding)
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'asset' => 'required|in:'.implode(',', BrandingService::ASSETS),
        ]);

        $branding->removeAsset($request->input('asset'));

        return back()->with('success', 'Plik usunięty');
    }

    // ===================================================================
    // Krok 4 — dane startowe
    // ===================================================================

    public function applyBaseline(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'preset' => 'required|in:'.implode(',', array_keys(SetupService::STATUS_PRESETS)),
            'sample_data' => 'nullable|boolean',
        ]);

        $created = $this->setup->applyBaseline($data['preset'], (bool) ($data['sample_data'] ?? false));

        $summary = "Statusy: +{$created['statuses']}, uprawnienia: +{$created['permissions']}, moduły: +{$created['modules']}";
        if ($created['sample'] > 0) {
            $summary .= ", dane przykładowe: {$created['sample']} klientów";
        }

        return back()->with('success', 'Dane startowe przygotowane. '.$summary);
    }

    // ===================================================================
    // Krok 5 — preferencje
    // ===================================================================

    public function savePreferences(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'app_timezone' => 'nullable|string|max:60',
            'default_calendar_view' => 'nullable|in:month,week,day',
            'items_per_page' => 'nullable|integer|min:10|max:200',
            'week_starts_monday' => 'nullable|boolean',
            'mail_from_address' => 'nullable|email|max:120',
            'mail_from_name' => 'nullable|string|max:120',
            'mail_signature' => 'nullable|string|max:5000',
        ]);

        $this->setup->savePreferences($data);

        return back()->with('success', 'Preferencje zapisane');
    }

    // ===================================================================
    // Krok 6 — moduły z marketplace
    // ===================================================================

    public function installModule(Request $request, MarketplaceService $marketplace)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate(['plugin_id' => 'required|string|max:100']);

        $result = $marketplace->installFromMarketplace($data['plugin_id']);

        if (! ($result['success'] ?? false)) {
            return back()->with('error', $result['message'] ?? 'Instalacja nie powiodła się');
        }

        $this->setup->markStep('modules');

        return back()->with('success', ($result['message'] ?? 'Moduł zainstalowany')
            .' Frontend zostanie przebudowany w tle — jeśli strona modułu nie działa, uruchom na serwerze: npm run build');
    }

    // ===================================================================
    // Nawigacja kreatora
    // ===================================================================

    public function skipStep(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'step' => 'required|string|max:40',
        ]);

        $step = collect(SetupService::STEPS)->firstWhere('key', $data['step']);

        if (! $step || ! $step['optional']) {
            return back()->with('error', 'Tego kroku nie można pominąć');
        }

        $this->setup->markStep($data['step'], 'skipped');

        return back();
    }

    public function complete(Request $request)
    {
        $this->authorizeAdmin($request);

        $this->setup->complete();

        return redirect()->route('dashboard')->with('success', 'Konfiguracja zakończona — miłej pracy!');
    }

    /** Ponowne uruchomienie kreatora z Ustawień. */
    public function restart(Request $request)
    {
        $this->authorizeAdmin($request);

        $this->setup->restart();

        return redirect()->route('setup.show');
    }

    // ===================================================================

    protected function isAdmin(Request $request): bool
    {
        return (bool) $request->user()?->hasAdminRights();
    }

    protected function authorizeAdmin(Request $request): void
    {
        abort_unless($this->isAdmin($request), 403, 'Konfigurację może przeprowadzić tylko administrator.');
    }
}
