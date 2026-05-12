<?php

namespace Modules\Infakt\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Infakt\Services\InfaktService;

class InfaktController extends Controller
{
    public function __construct(protected InfaktService $infakt) {}

    public function config(): Response
    {
        $apiKey = (string) Setting::get('infakt_api_key', '', 'core');
        $sandbox = (bool) Setting::get('infakt_sandbox', false, 'core');

        $ksefStatus = null;
        if ($this->infakt->isConfigured()) {
            try {
                $ksefStatus = $this->infakt->getKsefStatus();
            } catch (\Throwable $e) {
                // cisza
            }
        }

        return Inertia::render('Infakt/Config', [
            'status' => [
                'configured'    => $this->infakt->isConfigured(),
                'sandbox'       => $sandbox,
                'api_key_set'   => !empty($apiKey),
                'api_key_mask'  => $this->maskSecret($apiKey),
                'webhook_url'   => url('/infakt/webhook'),
                'ksef'          => $ksefStatus,
            ],
        ]);
    }

    public function saveCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => 'nullable|string|max:255',
            'sandbox' => 'nullable|boolean',
        ]);

        if (!empty($data['api_key'])) {
            Setting::set('infakt_api_key', trim($data['api_key']), 'core');
        }
        Setting::set('infakt_sandbox', (bool) ($data['sandbox'] ?? false), 'core');

        return back()->with('success', 'Konfiguracja inFakt zapisana');
    }

    public function test(): RedirectResponse
    {
        $result = $this->infakt->testConnection();
        if ($result['success'] ?? false) {
            $msg = 'Polaczenie OK';
            if (!empty($result['sandbox'])) $msg .= ' (sandbox)';
            if (!empty($result['account']['name'])) $msg .= ' — konto: ' . $result['account']['name'];
            return back()->with('success', $msg);
        }
        return back()->with('error', 'Blad: ' . ($result['message'] ?? 'unknown'));
    }

    /**
     * Streamuje PDF faktury z inFakt do przegladarki/downloadu.
     */
    public function invoicePdf(string $uuid, Request $request): HttpResponse|JsonResponse
    {
        $documentType = $request->get('document_type', 'original');
        $locale = $request->get('locale', 'pl');

        $pdf = $this->infakt->getInvoicePdf($uuid, $documentType, $locale);
        if (!$pdf) {
            return response()->json(['error' => 'Nie udalo sie pobrac PDF'], 404);
        }

        return response($pdf)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"faktura-{$uuid}.pdf\"");
    }

    /**
     * Webhook receiver dla zdarzen z inFakt (send_to_ksef_success, invoice_paid, draft_invoice_created, ...).
     * Aktualnie tylko loguje — w pelnej implementacji aktualizuje status Order/Invoice w CRM.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();
        $eventName = $payload['event']['name'] ?? 'unknown';
        $resourceUuid = $payload['resource']['uuid'] ?? null;

        Log::info('inFakt webhook received', [
            'event'    => $eventName,
            'uuid'     => $resourceUuid,
            'event_uuid' => $payload['event']['uuid'] ?? null,
        ]);

        // TODO: dispatch event do aktualizacji powiazanego Order::invoice_status
        return response()->json(['ok' => true]);
    }

    protected function maskSecret(?string $value): string
    {
        if (!$value) return '';
        if (strlen($value) <= 8) return str_repeat('•', strlen($value));
        return str_repeat('•', max(8, strlen($value) - 4)) . substr($value, -4);
    }
}
