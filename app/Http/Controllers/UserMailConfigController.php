<?php

namespace App\Http\Controllers;

use App\Models\UserMailConfig;
use App\Services\UserMailService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserMailConfigController extends Controller
{
    protected UserMailService $mailService;

    public function __construct(UserMailService $mailService)
    {
        $this->mailService = $mailService;
    }

    public function index()
    {
        $user = auth()->user();
        $configs = $user->mailConfigs()->orderBy('is_default', 'desc')->get();

        return Inertia::render('Settings/MailConfigs/Index', [
            'mailConfigs' => $configs,
            'emailHtmlFooter' => $user->email_html_footer ?? '',
        ]);
    }

    public function updateEmailFooter(Request $request)
    {
        $validated = $request->validate([
            'email_html_footer' => 'nullable|string|max:10000',
        ]);

        auth()->user()->update([
            'email_html_footer' => trim($validated['email_html_footer'] ?? '') ?: null,
        ]);

        return redirect()->back()->with('success', 'Stopka wiadomości została zapisana.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'mail_host' => 'required|string|max:255',
            'mail_port' => 'required|integer|min:1|max:65535',
            'mail_username' => 'required|string|max:255',
            'mail_password' => 'required|string',
            'mail_encryption' => 'required|in:tls,ssl,none',
            'mail_from_address' => 'required|email|max:255',
            'mail_from_name' => 'required|string|max:255',
        ]);

        if ($validated['mail_encryption'] === 'none') {
            $validated['mail_encryption'] = null;
        }

        $validated['user_id'] = auth()->id();
        
        // Jeśli to pierwsza konfiguracja, ustaw jako domyślną
        if (auth()->user()->mailConfigs()->count() === 0) {
            $validated['is_default'] = true;
        }

        $config = UserMailConfig::create($validated);

        return redirect()->back()->with('success', 'Konfiguracja serwera pocztowego została dodana.');
    }

    public function update(Request $request, UserMailConfig $mailConfig)
    {
        // Sprawdź czy konfiguracja należy do użytkownika
        if ($mailConfig->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'mail_host' => 'required|string|max:255',
            'mail_port' => 'required|integer|min:1|max:65535',
            'mail_username' => 'required|string|max:255',
            'mail_password' => 'nullable|string', // Opcjonalne przy aktualizacji
            'mail_encryption' => 'required|in:tls,ssl,none',
            'mail_from_address' => 'required|email|max:255',
            'mail_from_name' => 'required|string|max:255',
        ]);

        if ($validated['mail_encryption'] === 'none') {
            $validated['mail_encryption'] = null;
        }

        // Jeśli hasło nie zostało podane, usuń z aktualizacji
        if (empty($validated['mail_password'])) {
            unset($validated['mail_password']);
        }

        // Resetuj weryfikację jeśli zmieniono dane połączenia
        $connectionFields = ['mail_host', 'mail_port', 'mail_username', 'mail_encryption'];
        foreach ($connectionFields as $field) {
            if (isset($validated[$field]) && $mailConfig->$field !== $validated[$field]) {
                $validated['is_verified'] = false;
                $validated['verified_at'] = null;
                break;
            }
        }

        $mailConfig->update($validated);

        return redirect()->back()->with('success', 'Konfiguracja została zaktualizowana.');
    }

    public function destroy(UserMailConfig $mailConfig)
    {
        if ($mailConfig->user_id !== auth()->id()) {
            abort(403);
        }

        $wasDefault = $mailConfig->is_default;
        $mailConfig->delete();

        // Jeśli usunięto domyślną, ustaw pierwszą dostępną jako domyślną
        if ($wasDefault) {
            $firstConfig = auth()->user()->mailConfigs()->first();
            if ($firstConfig) {
                $firstConfig->setAsDefault();
            }
        }

        return redirect()->back()->with('success', 'Konfiguracja została usunięta.');
    }

    public function setDefault(UserMailConfig $mailConfig)
    {
        if ($mailConfig->user_id !== auth()->id()) {
            abort(403);
        }

        $mailConfig->setAsDefault();

        return redirect()->back()->with('success', 'Konfiguracja została ustawiona jako domyślna.');
    }

    public function test(UserMailConfig $mailConfig)
    {
        if ($mailConfig->user_id !== auth()->id()) {
            abort(403);
        }

        $result = $this->mailService->testConnection($mailConfig);

        if ($result['success']) {
            return redirect()->back()->with('success', $result['message']);
        }

        return redirect()->back()->with('error', $result['message']);
    }
}
