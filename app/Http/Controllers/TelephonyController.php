<?php

namespace App\Http\Controllers;

use App\Contracts\TelephonyProvider;
use App\Support\Providers\ProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jeden punkt wejścia dla dzwonienia z aplikacji, niezależny od centrali.
 *
 * Wcześniej front sam wybierał endpoint po nazwie modułu (`ringostat.callback`
 * albo `playcentrala.callback`), więc każda nowa centrala wymagała dopisania
 * kolejnego `if` w komponencie. Teraz rdzeń pyta rejestr o aktywnego dostawcę
 * telefonii i woła metodę z kontraktu — 3CX czy Twilio zadziałają bez zmiany
 * choćby jednej linii we froncie.
 */
class TelephonyController extends Controller
{
    public function __construct(protected ProviderRegistry $registry) {}

    public function call(Request $request): JsonResponse
    {
        $data = $request->validate([
            'destination' => 'required|string|max:30',
        ]);

        $provider = $this->registry->activeOrNull('telephony');

        if (!$provider instanceof TelephonyProvider) {
            return response()->json([
                'success' => false,
                'message' => 'Brak skonfigurowanej centrali telefonicznej.',
            ], 422);
        }

        if (!$provider->supportsClickToCall()) {
            return response()->json([
                'success' => false,
                'message' => "Centrala „{$provider->label()}” nie obsługuje dzwonienia z aplikacji.",
            ], 422);
        }

        $ok = $provider->click2call($request->user(), $data['destination']);

        return response()->json([
            'success' => $ok,
            'message' => $ok
                ? 'Łączenie — odbierz telefon.'
                : 'Nie udało się zainicjować połączenia. Sprawdź, czy masz uzupełniony numer wewnętrzny w profilu.',
        ], $ok ? 200 : 422);
    }
}
