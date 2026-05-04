<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ApiloService;
use App\Services\FakturowniaService;
use App\Support\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SettingController extends Controller
{
    /**
     * Wyświetl stronę ustawień systemowych
     */
    public function index()
    {
        $settings = Setting::where('module', 'core')
            ->orderBy('group')
            ->orderBy('order')
            ->get()
            ->groupBy('group');

        return Inertia::render('Admin/Settings/Index', [
            'settings' => $settings,
            'groups' => [
                'general'    => 'Ogólne',
                'company'    => 'Dane firmy',
                'mail'       => 'Poczta',
                'appearance' => 'Wygląd',
            ],
            'brand' => Brand::all(),
            'brandDefaults' => [
                'primary_color'   => '#E91E8C',
                'secondary_color' => '#9B26D9',
            ],
        ]);
    }

    /**
     * Zapisz ustawienia
     */
    public function update(Request $request)
    {
        $settings = $request->input('settings', []);

        foreach ($settings as $key => $value) {
            // Zapisz ustawienie
            Setting::set($key, $value, 'core');
        }

        // Wyczyść cały cache ustawień
        Cache::flush();

        return back()->with('success', 'Ustawienia zostały zapisane');
    }

    /**
     * Dodaj nowe ustawienie
     */
    public function store(Request $request)
    {
        $request->validate([
            'module' => 'required|string|max:50',
            'group' => 'required|string|max:50',
            'key' => 'required|string|max:100',
            'type' => 'required|in:string,boolean,integer,textarea,select,json',
            'label' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'value' => 'nullable',
            'options' => 'nullable|array',
        ]);

        Setting::create($request->all());

        return back()->with('success', 'Ustawienie zostało dodane');
    }

    /**
     * Usuń ustawienie
     */
    public function destroy(Setting $setting)
    {
        $setting->delete();

        return back()->with('success', 'Ustawienie zostało usunięte');
    }

    /**
     * Upload logo aplikacji
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,png,gif,svg,webp|max:2048',
        ]);

        // Usuń stare logo jeśli istnieje
        $oldLogo = Setting::get('app_logo', null);
        if ($oldLogo && Storage::disk('public')->exists(str_replace('/storage/', '', $oldLogo))) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $oldLogo));
        }

        // Zapisz nowe logo
        $path = $request->file('logo')->store('logos', 'public');
        $url = '/storage/' . $path;

        Setting::set('app_logo', $url, 'core');
        Cache::flush();

        return back()->with('success', 'Logo zostało zaktualizowane');
    }

    /**
     * Testuj połączenie z Fakturownia
     */
    public function testFakturownia(FakturowniaService $fakturownia)
    {
        $result = $fakturownia->testConnection();

        if ($result['success']) {
            return back()->with('success', "Połączenie z Fakturownia aktywne. Konto: {$result['account']['name']}");
        }

        return back()->with('error', "Błąd połączenia: {$result['message']}");
    }

    /**
     * Testuj połączenie z Apilo
     */
    public function testApilo(ApiloService $apilo)
    {
        $result = $apilo->testConnection();

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', "Błąd Apilo: {$result['message']}");
    }

    /**
     * Autoryzuj Apilo kodem autoryzacyjnym
     * Wymienia authorization_code na access_token + refresh_token
     */
    public function authorizeApilo(Request $request, ApiloService $apilo)
    {
        $request->validate([
            'authorization_code' => 'required|string',
        ]);

        $result = $apilo->authorizeWithCode($request->authorization_code);

        if ($result['success']) {
            // Wyczyść cache po zapisie nowych tokenów
            Cache::flush();
            return back()->with('success', $result['message'] . ' Token ważny do: ' . ($result['expires_at'] ?? ''));
        }

        return back()->with('error', $result['message']);
    }

}
