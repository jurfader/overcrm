<?php

namespace Modules\Fakturownia\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Modules\Fakturownia\Services\FakturowniaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class FakturowniaController extends Controller
{
    public function __construct(protected FakturowniaService $fakturownia) {}

    public function config(): Response
    {
        $token = (string) Setting::get('fakturownia_api_token', '', 'core');

        return Inertia::render('Fakturownia/Config', [
            'status' => [
                'configured'    => $this->fakturownia->isConfigured(),
                'subdomain'     => Setting::get('fakturownia_subdomain', '', 'core'),
                'api_token_set' => !empty($token),
                'api_token_mask'=> $this->maskSecret($token),
            ],
        ]);
    }

    public function saveCredentials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subdomain' => 'required|string|max:80',
            'api_token' => 'nullable|string|max:255',
        ]);

        Setting::set('fakturownia_subdomain', trim($data['subdomain']), 'core');
        if (!empty($data['api_token'])) {
            Setting::set('fakturownia_api_token', trim($data['api_token']), 'core');
        }

        return back()->with('success', 'Dane Fakturownia zapisane');
    }

    public function test(): RedirectResponse
    {
        $result = $this->fakturownia->testConnection();

        if ($result['success'] ?? false) {
            $msg = 'Połączenie OK';
            if (!empty($result['account']['name'])) $msg .= ' — konto: ' . $result['account']['name'];
            return back()->with('success', $msg);
        }

        return back()->with('error', 'Błąd: ' . ($result['message'] ?? 'unknown'));
    }

    public function invoicesByNip(Request $request): JsonResponse
    {
        $nip = FakturowniaService::normalizeNip($request->get('nip', ''));
        if (strlen($nip) < 10) {
            return response()->json(['invoices' => []]);
        }

        try {
            return response()->json(['invoices' => $this->fakturownia->getInvoicesForClient($nip)]);
        } catch (\Throwable $e) {
            Log::warning('fakturownia.invoicesByNip error', ['message' => $e->getMessage()]);
            return response()->json(['invoices' => []]);
        }
    }

    public function invoiceDetail(int $id): JsonResponse
    {
        try {
            $invoice = $this->fakturownia->getInvoice($id);
            return response()->json($invoice ?: ['error' => 'Nie znaleziono faktury']);
        } catch (\Throwable $e) {
            Log::warning('fakturownia.invoiceDetail error', ['id' => $id, 'message' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function invoicePdf(int $id): HttpResponse|JsonResponse
    {
        try {
            $base64 = $this->fakturownia->getInvoicePdf($id);
            if (!$base64) {
                return response()->json(['error' => 'Nie udało się pobrać PDF faktury'], 404);
            }

            return response(base64_decode($base64))
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="faktura-' . $id . '.pdf"');
        } catch (\Throwable $e) {
            Log::warning('fakturownia.invoicePdf error', ['id' => $id, 'message' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    protected function maskSecret(?string $value): string
    {
        if (!$value) return '';
        if (strlen($value) <= 8) return str_repeat('•', strlen($value));
        return str_repeat('•', max(8, strlen($value) - 4)) . substr($value, -4);
    }
}
