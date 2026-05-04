<?php

namespace Modules\Ringostat\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ringostat\Jobs\AnalyzeCallJob;
use Modules\Ringostat\Models\RingostatCall;
use Modules\Ringostat\Services\CallAiAnalyzer;
use Modules\Ringostat\Services\RingostatService;

class RingostatController extends Controller
{
    protected RingostatService $service;

    public function __construct(RingostatService $service)
    {
        $this->service = $service;
    }

    // ==================== WIDOKI ====================

    public function index(Request $request): Response
    {
        $user    = auth()->user();
        $isAdmin = $user->hasAdminRights() || $user->isManager();

        $query = RingostatCall::with(['client:id,name,phone', 'user:id,name', 'visit:id,title,visit_date,client_id'])
            ->latest('call_date');

        if (!$isAdmin) {
            $query->where('user_id', $user->id);
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->where('call_date', '>=', Carbon::parse($dateFrom)->startOfDay());
        }
        if ($dateTo = $request->get('date_to')) {
            $query->where('call_date', '<=', Carbon::parse($dateTo)->endOfDay());
        }
        if ($isAdmin && ($userId = $request->get('user_id'))) {
            $query->where('user_id', $userId);
        }
        if ($callType = $request->get('call_type')) {
            if ($callType === 'in') $query->incoming();
            elseif ($callType === 'out') $query->outgoing();
        }
        if ($disposition = $request->get('disposition')) {
            if ($disposition === 'answered') $query->answered();
            elseif ($disposition === 'missed') $query->missed();
        }
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('caller', 'like', '%' . $search . '%')
                  ->orWhere('destination', 'like', '%' . $search . '%')
                  ->orWhere('employee_name', 'like', '%' . $search . '%')
                  ->orWhereHas('client', fn($cq) => $cq->where('name', 'like', '%' . $search . '%'));
            });
        }

        $calls = $query->paginate(25)->withQueryString()->through(function ($call) {
            return [
                'id'                  => $call->id,
                'call_id'             => $call->call_id,
                'call_date'           => $call->call_date?->format('Y-m-d H:i:s'),
                'caller'              => $call->caller,
                'destination'         => $call->destination,
                'call_type'           => $call->call_type,
                'call_type_label'     => $call->call_type_label,
                'disposition'         => $call->disposition,
                'disposition_label'   => $call->disposition_label,
                'disposition_color'   => $call->disposition_color,
                'duration'            => $call->duration,
                'billsec'             => $call->billsec,
                'formatted_duration'  => $call->formatted_duration,
                'employee_name'       => $call->employee_name,
                'employee_id'         => $call->employee_id,
                'answered_by_number'  => $call->answered_by_number,
                'has_recording'       => $call->has_recording,
                'recording_url'       => $call->recording_url
                    ? route('ringostat.stream-recording', $call->id)
                    : null,
                'has_ai_analysis'     => !empty($call->ai_transcript) || !empty($call->ai_summary) || !empty($call->ai_analysis),
                'ai_transcript'       => $call->ai_transcript,
                'ai_summary'          => $call->ai_summary,
                'ai_customer_mood'    => $call->ai_customer_mood,
                'ai_employee_mood'    => $call->ai_employee_mood,
                'ai_overall_mood'     => $call->ai_overall_mood,
                'ai_recommendations'  => $call->ai_recommendations,
                'ai_analysis'         => $call->ai_analysis,
                'ai_profile_suggestions' => $call->ai_profile_suggestions,
                'user_id'             => $call->user_id,
                'user'                => $call->user ? ['id' => $call->user->id, 'name' => $call->user->name] : null,
                'client_id'           => $call->client_id,
                'client'              => $call->client ? ['id' => $call->client->id, 'name' => $call->client->name, 'phone' => $call->client->phone] : null,
                'visit_id'            => $call->visit_id,
                'visit'               => $call->visit ? [
                    'id'         => $call->visit->id,
                    'title'      => $call->visit->title,
                    'visit_date' => $call->visit->visit_date?->format('Y-m-d'),
                    'client_id'  => $call->visit->client_id,
                ] : null,
            ];
        });

        // Statystyki
        $statsQuery = RingostatCall::query();
        if (!$isAdmin) $statsQuery->where('user_id', $user->id);
        if ($dateFrom) {
            $statsQuery->where('call_date', '>=', Carbon::parse($dateFrom)->startOfDay());
        } else {
            $statsQuery->where('call_date', '>=', now()->startOfDay());
        }
        if ($dateTo) $statsQuery->where('call_date', '<=', Carbon::parse($dateTo)->endOfDay());
        if ($isAdmin && isset($userId)) $statsQuery->where('user_id', $userId);

        $totalCalls   = (clone $statsQuery)->count();
        $answeredCalls = (clone $statsQuery)->answered()->count();
        $missedCalls  = (clone $statsQuery)->missed()->count();
        $avgDuration  = (clone $statsQuery)->answered()->avg('billsec') ?? 0;

        $users = $isAdmin ? User::orderBy('name')->get(['id', 'name']) : [];

        return Inertia::render('Ringostat/Index', [
            'calls' => $calls,
            'stats' => [
                'total'    => $totalCalls,
                'answered' => $answeredCalls,
                'missed'   => $missedCalls,
                'avg_duration' => round($avgDuration),
            ],
            'users'         => $users,
            'filters'       => $request->only(['date_from', 'date_to', 'user_id', 'call_type', 'disposition', 'search']),
            'isConfigured'  => $this->service->isConfigured(),
            'isAdmin'       => $isAdmin,
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $period    = $request->get('period', 'day');
        $startDate = match ($period) {
            'week'  => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };

        $total    = RingostatCall::where('call_date', '>=', $startDate)->count();
        $answered = RingostatCall::where('call_date', '>=', $startDate)->answered()->count();
        $missed   = RingostatCall::where('call_date', '>=', $startDate)->missed()->count();
        $incoming = RingostatCall::where('call_date', '>=', $startDate)->incoming()->count();
        $outgoing = RingostatCall::where('call_date', '>=', $startDate)->outgoing()->count();
        $avgDuration = RingostatCall::where('call_date', '>=', $startDate)->answered()->avg('billsec') ?? 0;

        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date     = now()->subDays($i);
            $dayTotal = RingostatCall::whereDate('call_date', $date)->count();
            $dayAns   = RingostatCall::whereDate('call_date', $date)->answered()->count();
            $trend[]  = [
                'date'     => $date->format('d.m'),
                'total'    => $dayTotal,
                'answered' => $dayAns,
                'missed'   => $dayTotal - $dayAns,
            ];
        }

        return response()->json([
            'total'        => $total,
            'answered'     => $answered,
            'missed'       => $missed,
            'incoming'     => $incoming,
            'outgoing'     => $outgoing,
            'avg_duration' => round($avgDuration),
            'trend'        => $trend,
        ]);
    }

    // ==================== SYNCHRONIZACJA ====================

    public function syncCalls(Request $request): JsonResponse
    {
        $hours = $request->get('hours', 24);
        $from  = now()->subHours($hours)->format('Y-m-d H:i:s');
        $to    = now()->format('Y-m-d H:i:s');

        try {
            $calls = $this->service->getAllCalls($from, $to);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Błąd API: ' . $e->getMessage()], 500);
        }

        if (empty($calls)) {
            return response()->json([
                'success' => true,
                'message' => 'Brak nowych połączeń do synchronizacji',
                'synced'  => 0,
            ]);
        }

        $synced = 0;
        $errors = 0;

        foreach ($calls as $callData) {
            $callId = $callData['globalSessionId'] ?? $callData['callSessionId'] ?? null;
            if (!$callId) continue;

            try {
                $call = RingostatCall::updateOrCreate(
                    ['call_id' => $callId],
                    RingostatService::mapCallData($callData)
                );
                if (!$call->client_id) $call->matchClient();
                if (!$call->user_id) $call->matchUser();
                $synced++;
            } catch (\Exception $e) {
                $errors++;
                Log::error('Play sync error', ['call_id' => $callId, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'success'        => true,
            'message'        => "Zsynchronizowano {$synced} połączeń" . ($errors > 0 ? " ({$errors} błędów)" : ''),
            'synced'         => $synced,
            'errors'         => $errors,
            'total_from_api' => count($calls),
        ]);
    }

    public function rematchCalls(): JsonResponse
    {
        $count = 0;
        RingostatCall::chunk(100, function ($calls) use (&$count) {
            foreach ($calls as $call) {
                $call->matchClient();
                $call->matchUser();
                $count++;
            }
        });

        return response()->json([
            'success'   => true,
            'message'   => "Ponownie dopasowano {$count} połączeń",
            'rematched' => $count,
        ]);
    }

    // ==================== CLICK2CALL ====================

    public function callback(Request $request): JsonResponse
    {
        $request->validate(['destination' => 'required|string']);

        $user     = auth()->user();
        $playPhone = $user->play_phone ?? $user->phone ?? null;

        if (empty($playPhone)) {
            return response()->json([
                'success' => false,
                'message' => 'Nie masz przypisanego numeru telefonu Play w profilu (ustaw pole "Telefon Play")',
            ], 422);
        }

        $result = $this->service->initiateCallback($playPhone, $request->destination);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    // ==================== NAGRANIA (PROXY) ====================

    /**
     * Strumieniuj odszyfrowane nagranie do przeglądarki.
     * Uwierzytelnienie wymagane — zalogowany użytkownik.
     */
    public function streamRecording(int $callId)
    {
        $call = RingostatCall::findOrFail($callId);

        if (!$call->recording_url) {
            abort(404, 'Brak nagrania');
        }

        $response = $this->service->streamDecryptedRecording($call->recording_url);

        if (!$response) {
            abort(500, 'Nie udało się odszyfrować nagrania');
        }

        return $response;
    }

    // ==================== DANE KLIENTA ====================

    /**
     * Połączenia związane z wizytą — po client_id wizyty, visit_id lub numerach phones wizyty.
     */
    public function visitCalls(int $visitId): JsonResponse
    {
        $visit = \App\Models\ClientVisit::find($visitId);
        if (!$visit) {
            return response()->json(['calls' => []]);
        }

        $phoneNormalizedList = [];
        if (is_array($visit->phones)) {
            foreach ($visit->phones as $phone) {
                $digits = preg_replace('/\D+/', '', (string) $phone);
                if (strlen($digits) > 9 && str_starts_with($digits, '48')) {
                    $digits = substr($digits, 2);
                }
                if (strlen($digits) >= 7) {
                    $phoneNormalizedList[] = $digits;
                }
            }
        }

        $calls = RingostatCall::with(['user:id,name'])
            ->where(function ($q) use ($visit, $phoneNormalizedList) {
                $q->where('visit_id', $visit->id);
                if ($visit->client_id) {
                    $q->orWhere('client_id', $visit->client_id);
                }
                foreach ($phoneNormalizedList as $phoneNorm) {
                    $q->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(caller, '+', ''), ' ', ''), '-', ''), '(', '') LIKE ?",
                        ['%' . $phoneNorm . '%']
                    );
                    $q->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(destination, '+', ''), ' ', ''), '-', ''), '(', '') LIKE ?",
                        ['%' . $phoneNorm . '%']
                    );
                }
            })
            ->latest('call_date')
            ->limit(100)
            ->get()
            ->map(fn($call) => $this->formatCallForList($call));

        return response()->json(['calls' => $calls]);
    }

    private function formatCallForList(RingostatCall $call): array
    {
        return [
            'id'               => $call->id,
            'call_date'        => $call->call_date?->format('Y-m-d H:i'),
            'caller'           => $call->caller,
            'destination'      => $call->destination,
            'call_type'        => $call->call_type,
            'call_type_label'  => $call->call_type_label,
            'disposition'      => $call->disposition,
            'disposition_label' => $call->disposition_label,
            'disposition_color' => $call->disposition_color,
            'formatted_duration' => $call->formatted_duration,
            'billsec'          => $call->billsec,
            'recording_url'    => $call->recording_url
                ? route('ringostat.stream-recording', $call->id)
                : null,
            'has_recording'    => $call->has_recording,
            'has_ai_data'      => $call->has_ai_data,
            'ai_summary'       => $call->ai_summary,
            'ai_transcript'    => $call->ai_transcript,
            'ai_customer_mood' => $call->ai_customer_mood,
            'ai_employee_mood' => $call->ai_employee_mood,
            'ai_recommendations' => $call->ai_recommendations,
            'employee_name'    => $call->employee_name ?? $call->user?->name,
            'user'             => $call->user ? ['id' => $call->user->id, 'name' => $call->user->name] : null,
        ];
    }

    public function clientCalls(int $clientId): JsonResponse
    {
        $calls = RingostatCall::with(['user:id,name'])
            ->where('client_id', $clientId)
            ->latest('call_date')
            ->limit(50)
            ->get()
            ->map(fn($call) => [
                'id'               => $call->id,
                'call_date'        => $call->call_date->format('Y-m-d H:i'),
                'caller'           => $call->caller,
                'destination'      => $call->destination,
                'call_type'        => $call->call_type,
                'call_type_label'  => $call->call_type_label,
                'disposition'      => $call->disposition,
                'disposition_label' => $call->disposition_label,
                'disposition_color' => $call->disposition_color,
                'formatted_duration' => $call->formatted_duration,
                'billsec'          => $call->billsec,
                'recording_url'    => $call->recording_url
                    ? route('ringostat.stream-recording', $call->id)
                    : null,
                'has_recording'    => $call->has_recording,
                'has_ai_data'      => $call->has_ai_data,
                'ai_summary'       => $call->ai_summary,
                'ai_transcript'    => $call->ai_transcript,
                'ai_customer_mood' => $call->ai_customer_mood,
                'ai_employee_mood' => $call->ai_employee_mood,
                'ai_recommendations' => $call->ai_recommendations,
                'employee_name'    => $call->employee_name ?? $call->user?->name,
                'user'             => $call->user ? ['id' => $call->user->id, 'name' => $call->user->name] : null,
            ]);

        return response()->json(['calls' => $calls]);
    }

    public function dailyReportCalls(Request $request): JsonResponse
    {
        $date   = $request->get('date', now()->toDateString());
        $userId = $request->get('user_id');

        $query = RingostatCall::with(['client:id,name', 'user:id,name'])
            ->whereDate('call_date', $date);

        if ($userId) $query->where('user_id', $userId);

        $calls = $query->orderBy('call_date')->get()->map(fn($call) => [
            'id'              => $call->id,
            'call_date'       => $call->call_date->format('H:i'),
            'caller'          => $call->caller,
            'destination'     => $call->destination,
            'call_type'       => $call->call_type,
            'call_type_label' => $call->call_type_label,
            'disposition'     => $call->disposition,
            'disposition_label' => $call->disposition_label,
            'disposition_color' => $call->disposition_color,
            'formatted_duration' => $call->formatted_duration,
            'billsec'         => $call->billsec,
            'recording_url'   => $call->recording_url
                ? route('ringostat.stream-recording', $call->id)
                : null,
            'has_recording'   => $call->has_recording,
            'employee_name'   => $call->employee_name ?? $call->user?->name,
            'client'          => $call->client ? ['id' => $call->client->id, 'name' => $call->client->name] : null,
        ]);

        $total       = $calls->count();
        $answered    = $calls->whereIn('disposition', ['ANSWERED', 'CONNECTED'])->count();
        $totalDuration = $calls->sum('billsec');

        return response()->json([
            'calls' => $calls->values(),
            'stats' => [
                'total'                  => $total,
                'answered'               => $answered,
                'missed'                 => $total - $answered,
                'total_duration'         => $totalDuration,
                'formatted_total_duration' => sprintf('%d:%02d', floor($totalDuration / 60), $totalDuration % 60),
            ],
        ]);
    }

    // ==================== ADMIN ====================

    public function testConnection(): JsonResponse
    {
        $result = $this->service->testConnection();
        return response()->json($result);
    }

    // ==================== AI ANALIZA ====================

    /**
     * Wystartuj analizę AI w tle (dispatch do queue).
     * Frontend potem polluje GET /analyze-call/{id}/status aż has_ai_analysis = true.
     * Powód: synchroniczna analiza trwa >100s i wpada w 524 Cloudflare timeout.
     */
    public function analyzeCall(int $callId): JsonResponse
    {
        $call = RingostatCall::findOrFail($callId);

        if (!$call->has_recording) {
            return response()->json(['success' => false, 'message' => 'To połączenie nie ma nagrania'], 422);
        }

        // Cache zwracaj tylko gdy mamy PEŁNĄ analizę (z scores), a nie samego summary
        // — w przeciwnym razie "Analizuj ponownie" zwracałoby ten sam komunikat błędu.
        $hasFullAnalysis = !empty($call->ai_analysis['scores'] ?? null);
        if ($hasFullAnalysis) {
            return response()->json([
                'success' => true,
                'message' => 'Analiza już istnieje',
                'data'    => $this->buildAnalysisPayload($call),
            ]);
        }

        // Wyzeruj poprzedni częściowy/błędny stan, żeby polling wiedział kiedy nowa analiza skończy
        $call->update([
            'ai_transcript' => null,
            'ai_summary' => null,
            'ai_customer_mood' => null,
            'ai_employee_mood' => null,
            'ai_overall_mood' => null,
            'ai_recommendations' => null,
            'ai_analysis' => null,
        ]);

        AnalyzeCallJob::dispatch($call->id);

        return response()->json([
            'success' => true,
            'message' => 'Analiza w toku — pollu /status',
            'queued'  => true,
        ], 202);
    }

    /**
     * Status analizy do polowania przez UI.
     * Zwraca pełen payload gdy has_ai_analysis=true, albo {pending: true}.
     */
    public function analyzeCallStatus(int $callId): JsonResponse
    {
        $call = RingostatCall::findOrFail($callId);

        $hasAnalysis = !empty($call->ai_transcript) || !empty($call->ai_summary) || !empty($call->ai_analysis);

        if (!$hasAnalysis) {
            return response()->json(['success' => true, 'pending' => true]);
        }

        return response()->json([
            'success' => true,
            'pending' => false,
            'data'    => $this->buildAnalysisPayload($call),
        ]);
    }

    private function buildAnalysisPayload(RingostatCall $call): array
    {
        return [
            'ai_summary'             => $call->ai_summary,
            'ai_transcript'          => $call->ai_transcript,
            'ai_customer_mood'       => $call->ai_customer_mood,
            'ai_employee_mood'       => $call->ai_employee_mood,
            'ai_overall_mood'        => $call->ai_overall_mood,
            'ai_recommendations'     => $call->ai_recommendations,
            'ai_analysis'            => $call->ai_analysis,
            'ai_profile_suggestions' => $call->ai_profile_suggestions,
            'has_ai_analysis'        => true,
        ];
    }

    public function applyProfileSuggestions(Request $request, int $callId): JsonResponse
    {
        $call = RingostatCall::with('client')->findOrFail($callId);

        if (!$call->client) {
            return response()->json(['success' => false, 'message' => 'Połączenie nie jest przypisane do klienta'], 422);
        }

        $accepted = $request->input('accepted', []);
        if (empty($accepted)) {
            return response()->json(['success' => false, 'message' => 'Nie wybrano żadnych sugestii'], 422);
        }

        $suggestions = $call->ai_profile_suggestions ?? [];
        $client      = $call->client;
        $profile     = $client->profile ?? [];
        $applied     = [];

        $fieldMap = [
            'venue_type'        => 'venue.venue_type',
            'venue_size'        => 'venue.venue_size',
            'kitchen_staff'     => 'venue.kitchen_staff',
            'total_staff'       => 'venue.total_staff',
            'years_in_business' => 'venue.years_in_business',
            'specialty'         => 'concept.specialty',
            'cuisine'           => 'concept.cuisine',
            'price_level'       => 'concept.price_level',
            'delivery'          => 'sales.delivery',
            'delivery_volume'   => 'sales.delivery_volume',
            'platforms'         => 'sales.platforms',
            'rush_hours'        => 'sales.rush_hours',
            'customer_profiles' => 'customers.profiles',
            'serves_chicken'    => 'chicken.serves_chicken',
            'serving_form'      => 'chicken.serving_form',
            'chicken_volume'    => 'chicken.volume',
            'own_production'    => 'kitchen.own_production',
            'uses_semi_finished' => 'kitchen.uses_semi_finished',
            'suppliers'         => 'kitchen.suppliers',
            'decision_maker'    => 'organization.decision_maker',
            'ordering_person'   => 'organization.ordering_person',
            'ordering_frequency' => 'organization.ordering_frequency',
            'personality'       => 'mental.personality',
            'promo_activities'  => 'potential.promo_activities',
            'current_products'  => 'potential.current_products',
            'menu_changes'      => 'potential.menu_changes',
            'open_to_tests'     => 'potential.open_to_tests',
        ];

        foreach ($accepted as $key) {
            if (!isset($suggestions[$key], $fieldMap[$key])) continue;

            [$section, $field] = explode('.', $fieldMap[$key]);
            $profile[$section]          ??= [];
            $profile[$section][$field]  = $suggestions[$key];
            $applied[]                  = $key;
        }

        if (!empty($applied)) {
            $client->update(['profile' => $profile]);
            $remaining = array_diff_key($suggestions, array_flip($applied));
            $call->update(['ai_profile_suggestions' => !empty($remaining) ? $remaining : null]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Zastosowano ' . count($applied) . ' sugestii',
            'applied' => $applied,
        ]);
    }

    // ==================== WEBHOOK PLAY ====================

    /**
     * Webhook — Play Wirtualna Centralka wysyła CallModel po zakończeniu połączenia.
     * Konfiguracja w panelu Play: API → Notyfikacje → Webhook URL
     * Metoda autentykacji: Basic (login/hasło z ustawień play_webhook_login / play_webhook_password)
     */
    public function webhookPlayCall(Request $request): JsonResponse
    {
        // Walidacja Basic Auth webhookiem (opcjonalna — jeśli skonfigurowana)
        $webhookLogin    = \App\Models\Setting::get('play_webhook_login', '', 'ringostat') ?? '';
        $webhookPassword = \App\Models\Setting::get('play_webhook_password', '', 'ringostat') ?? '';

        if (!empty($webhookLogin) && !empty($webhookPassword)) {
            $authHeader = $request->header('Authorization', '');
            $expected   = 'Basic ' . base64_encode($webhookLogin . ':' . $webhookPassword);

            if ($authHeader !== $expected) {
                Log::warning('Play webhook: błąd autoryzacji', ['ip' => $request->ip()]);
                return response()->json(['status' => 'unauthorized'], 401);
            }
        }

        Log::info('Play webhook received', ['payload' => $request->all()]);

        $callData = $request->all();

        // Klucz identyfikujący połączenie
        $callId = $callData['globalSessionId'] ?? $callData['callSessionId'] ?? $callData['mainSessionId'] ?? null;

        if (empty($callId)) {
            // Test ping z Play panelu
            return response()->json(['status' => 'ok', 'message' => 'Webhook endpoint active']);
        }

        try {
            $mapped = RingostatService::mapCallData($callData);
            $call   = RingostatCall::updateOrCreate(
                ['call_id' => $callId],
                $mapped
            );

            if (!$call->client_id) $call->matchClient();
            if (!$call->user_id)   $call->matchUser();

            Log::info('Play webhook: call saved', ['call_id' => $callId, 'status' => $mapped['disposition']]);
        } catch (\Exception $e) {
            Log::error('Play webhook: błąd zapisu', ['call_id' => $callId, 'error' => $e->getMessage()]);
        }

        return response()->json(['status' => 'ok']);
    }
}
