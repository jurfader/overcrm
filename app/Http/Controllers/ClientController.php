<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientVisit;
use App\Models\SentEmail;
use App\Models\Task;
use App\Models\User;
use App\Services\GusService;
use App\Support\License;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Ringostat\Services\RingostatService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientController extends Controller
{
    private function profileOptions(): array
    {
        return [
            'city_sizes' => ['małe' => 'Małe (do 20 tys.)', 'średnie' => 'Średnie (20-100 tys.)', 'duże' => 'Duże (100 tys.+)'],
            'locations' => ['centrum' => 'Centrum', 'osiedle' => 'Osiedle', 'przy_drodze' => 'Przy drodze', 'galeria' => 'Galeria handlowa', 'dworzec' => 'Dworzec/lotnisko', 'inne' => 'Inne'],
            'venue_types' => ['stacjonarny' => 'Stacjonarny', 'kontener' => 'Kontener', 'food_truck' => 'Food truck', 'przyczepa' => 'Przyczepa', 'wyspa' => 'Wyspa (galeria)', 'inne' => 'Inne'],
            'price_levels' => ['niski' => 'Niski', 'średni' => 'Średni', 'wysoki' => 'Wysoki', 'premium' => 'Premium'],
            'platforms' => ['pyszne' => 'Pyszne.pl', 'uber_eats' => 'Uber Eats', 'glovo' => 'Glovo', 'wolt' => 'Wolt', 'bolt_food' => 'Bolt Food', 'inne' => 'Inne'],
            'customer_profiles' => ['turysci' => 'Turyści', 'mlodziez' => 'Młodzież', 'studenci' => 'Studenci', 'rodziny' => 'Rodziny', 'pracownicy' => 'Pracownicy biurowi', 'imprezy' => 'Imprezy/eventy', 'koncerty' => 'Koncerty', 'nocni' => 'Nocni klienci'],
            'decision_makers' => ['wlasciciel' => 'Właściciel', 'menedzer' => 'Menedżer', 'kucharz' => 'Szef kuchni', 'inny' => 'Inna osoba'],
            'personalities' => ['szybki' => 'Szybki', 'spokojny' => 'Spokojny', 'lubi_mowic' => 'Lubi mówić', 'konkretny' => 'Konkretny', 'analityczny' => 'Analityczny', 'emocjonalny' => 'Emocjonalny', 'negocjator' => 'Negocjator'],
        ];
    }

    private function profileValidationRules(): array
    {
        return [
            'profile' => 'nullable|array',
            'profile.venue' => 'nullable|array',
            'profile.venue.city_size' => 'nullable|string',
            'profile.venue.location' => 'nullable|string',
            'profile.venue.venue_type' => 'nullable|string',
            'profile.venue.venue_size' => 'nullable|string|max:50',
            'profile.venue.kitchen_staff' => 'nullable|integer|min:0',
            'profile.venue.total_staff' => 'nullable|integer|min:0',
            'profile.venue.years_in_business' => 'nullable|integer|min:0',
            'profile.venue.venue_birthday' => 'nullable|date',
            'profile.concept' => 'nullable|array',
            'profile.concept.specialty' => 'nullable|string|max:255',
            'profile.concept.cuisine' => 'nullable|string|max:255',
            'profile.concept.price_level' => 'nullable|string',
            'profile.sales' => 'nullable|array',
            'profile.sales.delivery' => 'nullable|boolean',
            'profile.sales.delivery_volume' => 'nullable|string|max:100',
            'profile.sales.platforms' => 'nullable|array',
            'profile.sales.rush_hours' => 'nullable|string|max:255',
            'profile.customers' => 'nullable|array',
            'profile.customers.profiles' => 'nullable|array',
            'profile.kitchen' => 'nullable|array',
            'profile.kitchen.own_production' => 'nullable|boolean',
            'profile.kitchen.uses_semi_finished' => 'nullable|boolean',
            'profile.kitchen.suppliers' => 'nullable|string|max:500',
            'profile.organization' => 'nullable|array',
            'profile.organization.decision_maker' => 'nullable|string',
            'profile.organization.ordering_person' => 'nullable|string|max:255',
            'profile.organization.ordering_frequency' => 'nullable|string|max:100',
            'profile.mental' => 'nullable|array',
            'profile.mental.personality' => 'nullable|array',
            'profile.mental.approach_notes' => 'nullable|string|max:1000',
            'profile.potential' => 'nullable|array',
            'profile.potential.promo_activities' => 'nullable|string|max:500',
            'profile.potential.media_quality' => 'nullable|string|max:255',
            'profile.potential.current_products' => 'nullable|string|max:500',
            'profile.potential.menu_changes' => 'nullable|boolean',
            'profile.potential.open_to_tests' => 'nullable|boolean',
            'profile.potential.notes' => 'nullable|string|max:1000',
            'profile.discounts' => 'nullable|string|max:500',
            'profile.payment_form' => 'nullable|string|max:255',
            'profile.delivery_info' => 'nullable|string|max:500',
        ];
    }
    /**
     * Pobierz dane firmy z GUS po NIP
     */
    public function lookupNip(Request $request, GusService $gus)
    {
        $request->validate([
            'nip' => 'required|string|min:10|max:13',
        ]);
        
        // Oczyść NIP z myślników i spacji
        $nip = preg_replace('/[^0-9]/', '', $request->nip);
        
        if (strlen($nip) !== 10) {
            return response()->json([
                'success' => false,
                'message' => 'NIP musi mieć 10 cyfr',
            ], 422);
        }
        
        // Walidacja sumy kontrolnej NIP
        if (!GusService::validateNip($nip)) {
            return response()->json([
                'success' => false,
                'message' => 'Nieprawidłowy NIP - błędna suma kontrolna',
            ], 422);
        }
        
        // Sprawdź czy klient z tym NIP już istnieje
        $existingClient = Client::whereRaw("REPLACE(REPLACE(REPLACE(nip, ' ', ''), '-', ''), '.', '') = ?", [$nip])
            ->first(['id', 'name', 'nip', 'type']);
        if ($existingClient) {
            return response()->json([
                'success' => true,
                'existing_client' => [
                    'id' => $existingClient->id,
                    'name' => $existingClient->name,
                    'nip' => $existingClient->nip,
                    'type' => $existingClient->type,
                ],
            ]);
        }

        $data = $gus->getByNip($nip);
        
        if ($data) {
            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Nie znaleziono firmy o podanym NIP w rejestrach GUS/VAT',
        ], 404);
    }

    /**
     * Wyszukiwarka klientów (autocomplete) – zwraca JSON
     */
    /**
     * Zwróć pełnego klienta jako JSON — dla frontendu (modal wizyty po zmianie klienta).
     * Bez Inertii, bez extra relacji — sam Client model ze wszystkimi polami.
     */
    public function showJson(Client $client): JsonResponse
    {
        return response()->json([
            'success' => true,
            'client' => $client->only([
                'id', 'type', 'name', 'short_name', 'nip', 'regon',
                'email', 'phone', 'phone2', 'website',
                'street', 'building_number', 'apartment_number',
                'postal_code', 'city', 'country',
                'contact_person', 'contact_email', 'contact_phone',
                'status', 'client_status', 'notes', 'birthday', 'profile',
            ]),
        ]);
    }

    public function search(Request $request)
    {
        $term = trim($request->get('q', ''));
        $limit = min((int) $request->get('limit', 20), 50);

        $query = Client::where('status', 'active')
            ->orderBy('name')
            ->limit($limit);

        if (strlen($term) >= 2) {
            $query->search($term);
        }

        $clients = $query->get(['id', 'name', 'short_name', 'nip', 'email']);

        return response()->json([
            'clients' => $clients->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->short_name ?: $c->name,
                'full_name' => $c->name,
            ]),
        ]);
    }

    /**
     * Lista klientów
     */
    public function index(Request $request): Response
    {
        $query = Client::query()
            ->withCount(['tasks', 'tasks as active_tasks_count' => fn($q) => 
                $q->whereHas('status', fn($s) => $s->where('is_final', false))
            ]);

        // Filtrowanie
        if ($search = $request->get('search')) {
            $query->search($search);
        }

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Sortowanie
        $sortBy = $request->get('sort', 'name');
        $sortDir = $request->get('dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        $clients = $query->paginate(15)->withQueryString();

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'filters' => [
                'search' => $request->get('search', ''),
                'type' => $request->get('type', ''),
                'status' => $request->get('status', ''),
                'sort' => $sortBy,
                'dir' => $sortDir,
            ],
            'types' => [
                'company' => 'Firma',
                'person' => 'Osoba prywatna',
            ],
            'statuses' => [
                'active' => 'Aktywny',
                'inactive' => 'Nieaktywny',
                'potential' => 'Potencjalny',
            ],
        ]);
    }

    /**
     * Formularz tworzenia
     */
    public function create(): Response
    {
        return Inertia::render('Clients/Form', [
            'client' => null,
            'types' => [
                'company' => 'Firma',
                'person' => 'Osoba prywatna',
            ],
            'statuses' => [
                'active' => 'Aktywny',
                'inactive' => 'Nieaktywny',
                'potential' => 'Potencjalny',
            ],
            'clientStatusOptions' => ['Stripsiak', 'Test', 'Allegro', 'Inny'],
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'profileOptions' => $this->profileOptions(),
        ]);
    }

    /**
     * Zapisz nowego klienta
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        License::guard('Dodawanie klienta wymaga ważnej licencji');
        try {
            $validated = $this->validateStore($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Błąd walidacji',
                    'errors' => $e->errors(),
                ], 422);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($request->wantsJson()) {
                report($e);
                return response()->json([
                    'success' => false,
                    'message' => 'Błąd serwera: ' . $e->getMessage(),
                ], 500);
            }
            throw $e;
        }

        // Sprawdź duplikat NIP — jeśli klient z tym NIP już istnieje, zwróć go zamiast błędu DB
        if (!empty($validated['nip'])) {
            $normalizedNip = preg_replace('/[^0-9]/', '', $validated['nip']);
            if (strlen($normalizedNip) === 10) {
                $existing = Client::whereRaw("REPLACE(REPLACE(REPLACE(nip, ' ', ''), '-', ''), '.', '') = ?", [$normalizedNip])->first();
                if ($existing) {
                    if ($request->wantsJson()) {
                        return response()->json([
                            'success'  => true,
                            'existing' => true,
                            'message'  => "Klient z tym NIP już istnieje: {$existing->name}",
                            'client'   => [
                                'id' => $existing->id, 'name' => $existing->name,
                                'type' => $existing->type, 'nip' => $existing->nip,
                            ],
                        ]);
                    }
                    return redirect()->route('clients.show', $existing->id)
                        ->with('warning', "Klient z tym NIP już istnieje: {$existing->name}");
                }
            }
        }

        try {
            $client = Client::create($validated);
        } catch (\Throwable $e) {
            report($e);
            $msg = str_contains($e->getMessage(), 'Duplicate entry') && str_contains($e->getMessage(), 'nip_normalized')
                ? 'Klient z tym NIP już istnieje w bazie (możliwe że jako soft-deleted — skontaktuj się z adminem).'
                : 'Nie udało się zapisać klienta: ' . $e->getMessage();

            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }
            return back()->withErrors(['save' => $msg])->withInput();
        }

        ActivityLog::log('create', $client, "Utworzono klienta: {$client->name}");
        $this->syncClientToRingostat($client);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'client' => [
                    'id' => $client->id,
                    'name' => $client->name,
                    'type' => $client->type,
                    'nip' => $client->nip,
                ],
            ]);
        }

        $redirectRoute = $request->header('Referer') && str_contains($request->header('Referer'), 'calendar')
            ? 'calendar.index'
            : 'clients.index';

        return redirect()->route($redirectRoute)
            ->with('success', 'Klient został dodany.')
            ->with('newClient', [
                'id' => $client->id,
                'name' => $client->name,
                'type' => $client->type,
                'nip' => $client->nip,
            ]);
    }

    /**
     * Szybkie tworzenie klienta — flow z kalendarza, konwersji leada, kanbana.
     *
     * Walidacja minimalna: name (wymagane) + type (company|person). Reszta pól opcjonalna.
     * NIP jeśli podany musi być unikalny — jeśli już istnieje, zwracamy istniejącego klienta
     * zamiast błędu (UX: handlowiec nie wie że firma już jest w bazie).
     *
     * Zwraca zawsze JSON — bez Inertii, żeby n8n/fetch mogły obsłużyć.
     */
    public function quickStore(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name'            => 'required|string|max:255',
                'type'            => 'nullable|in:company,person',
                'nip'             => 'nullable|string|max:15',
                'regon'           => 'nullable|string|max:14',
                'email'           => 'nullable|email|max:255',
                'phone'           => 'nullable|string|max:20',
                'street'          => 'nullable|string|max:255',
                'building_number' => 'nullable|string|max:10',
                'apartment_number'=> 'nullable|string|max:10',
                'postal_code'     => 'nullable|string|max:10',
                'city'            => 'nullable|string|max:100',
                'contact_person'  => 'nullable|string|max:255',
                'notes'           => 'nullable|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Błąd walidacji',
                'errors'  => $e->errors(),
            ], 422);
        }

        $validated['type']   = $validated['type']   ?? 'company';
        $validated['status'] = 'active';

        if (!empty($validated['nip'])) {
            $normalizedNip = preg_replace('/[^0-9]/', '', $validated['nip']);
            $existing = Client::whereRaw("REPLACE(REPLACE(REPLACE(nip, ' ', ''), '-', ''), '.', '') = ?", [$normalizedNip])->first();
            if ($existing) {
                return response()->json([
                    'success'  => true,
                    'existing' => true,
                    'client'   => $this->quickClientPayload($existing),
                    'message'  => "Klient z tym NIP już istnieje: {$existing->name}",
                ]);
            }
        }

        try {
            $client = Client::create($validated);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Nie udało się zapisać klienta: ' . $e->getMessage(),
            ], 500);
        }

        ActivityLog::log('create', $client, "Utworzono klienta: {$client->name}");
        $this->syncClientToRingostat($client);

        return response()->json([
            'success'  => true,
            'existing' => false,
            'client'   => $this->quickClientPayload($client),
        ]);
    }

    private function quickClientPayload(Client $client): array
    {
        return [
            'id'              => $client->id,
            'name'            => $client->name,
            'short_name'      => $client->short_name,
            'type'            => $client->type,
            'nip'             => $client->nip,
            'regon'           => $client->regon,
            'email'           => $client->email,
            'phone'           => $client->phone,
            'street'          => $client->street,
            'building_number' => $client->building_number,
            'city'            => $client->city,
            'postal_code'     => $client->postal_code,
            'status'          => $client->status,
        ];
    }

    private function validateStore(Request $request): array
    {
        $validated = $request->validate(array_merge([
            'type' => 'required|in:company,person,individual',
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:100',
            'nip' => 'nullable|string|max:15',
            'regon' => 'nullable|string|max:14',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'phone2' => 'nullable|string|max:20',
            'website' => 'nullable|string|max:255',
            'street' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'building_number' => 'nullable|string|max:10',
            'apartment_number' => 'nullable|string|max:10',
            'postal_code' => 'nullable|string|max:10',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'status' => 'nullable|in:active,inactive,potential',
            'client_status' => 'nullable|string|max:50',
            'assigned_to' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'birthday' => 'nullable|date',
        ], $this->profileValidationRules()));

        // Mapowanie 'individual' na 'person' dla kompatybilności
        if (isset($validated['type']) && $validated['type'] === 'individual') {
            $validated['type'] = 'person';
        }

        // Mapowanie 'address' na 'street' jeśli street nie jest ustawiony
        if (isset($validated['address']) && !isset($validated['street'])) {
            $validated['street'] = $validated['address'];
        }
        unset($validated['address']);

        // Domyślny status
        if (!isset($validated['status'])) {
            $validated['status'] = 'active';
        }

        $validated['assigned_to'] = !empty($validated['assigned_to'] ?? null) ? (int) $validated['assigned_to'] : null;
        $validated['client_status'] = !empty(trim($validated['client_status'] ?? '')) ? trim($validated['client_status']) : null;
        $validated['created_by'] = auth()->id();

        return $validated;
    }

    /**
     * Pokaż szczegóły klienta
     */
    public function show(Client $client): Response
    {
        $client->load(['creator', 'assignee:id,name', 'tasks' => fn($q) => $q->with(['status', 'assignee'])->latest()->limit(5)]);

        // Ostatnie spotkania (wizyty z kalendarza) – z opisem do synchronizacji w karcie
        $clientVisits = $client->clientVisits()
            ->with(['user:id,name', 'status:id,name,color'])
            ->orderByDesc('visit_date')
            ->orderByDesc('visit_time')
            ->limit(10)
            ->get(['id', 'visit_date', 'visit_time', 'title', 'description', 'notes', 'user_id', 'status_id']);

        // Opiekun handlowy: najpierw bezpośredni (assigned_to), potem z wizyt/zadań
        $opiekun = $client->assignee ? ['id' => $client->assignee->id, 'name' => $client->assignee->name] : null;
        if (!$opiekun) {
            $userIds = collect()
                ->merge(ClientVisit::where('client_id', $client->id)->whereNotNull('user_id')->pluck('user_id'))
                ->merge(Task::where('client_id', $client->id)->whereNotNull('assigned_to')->pluck('assigned_to'))
                ->unique()->filter()->values();
            $assignedHandlowcy = $userIds->isNotEmpty()
                ? User::whereIn('id', $userIds)->get(['id', 'name'])->map(fn($u) => ['id' => $u->id, 'name' => $u->name])
                : collect();
        } else {
            $assignedHandlowcy = collect([$opiekun]);
        }

        $sentEmails = SentEmail::where('client_id', $client->id)
            ->with(['user:id,name', 'template:id,name'])
            ->orderByRaw('COALESCE(sent_at, created_at) DESC')
            ->get(['id', 'subject', 'to_email', 'status', 'sent_at', 'created_at', 'error_message', 'user_id', 'email_template_id', 'html_content']);

        $summaries = $client->summaries()
            ->orderBy('generated_at', 'desc')
            ->get(['id', 'summary', 'generated_at', 'client_visit_id']);

        return Inertia::render('Clients/Show', [
            'client' => $client,
            'clientVisits' => $clientVisits,
            'assignedHandlowcy' => $assignedHandlowcy,
            'sentEmails' => $sentEmails,
            'summaries' => $summaries,
        ]);
    }

    /**
     * Formularz edycji
     */
    public function edit(Client $client): Response
    {
        return Inertia::render('Clients/Form', [
            'client' => $client,
            'types' => [
                'company' => 'Firma',
                'person' => 'Osoba prywatna',
            ],
            'statuses' => [
                'active' => 'Aktywny',
                'inactive' => 'Nieaktywny',
                'potential' => 'Potencjalny',
            ],
            'clientStatusOptions' => ['Stripsiak', 'Test', 'Allegro', 'Inny'],
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'profileOptions' => $this->profileOptions(),
        ]);
    }

    /**
     * Zaktualizuj klienta
     */
    public function update(Request $request, Client $client): RedirectResponse|JsonResponse
    {
        License::guard('Edycja klienta wymaga ważnej licencji');
        $validated = $request->validate(array_merge([
            'type' => 'required|in:company,person',
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:100',
            'nip' => 'nullable|string|max:15',
            'regon' => 'nullable|string|max:14',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'phone2' => 'nullable|string|max:20',
            'website' => 'nullable|string|max:255',
            'street' => 'nullable|string|max:255',
            'building_number' => 'nullable|string|max:10',
            'apartment_number' => 'nullable|string|max:10',
            'postal_code' => 'nullable|string|max:10',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'status' => 'required|in:active,inactive,potential',
            'client_status' => 'nullable|string|max:50',
            'assigned_to' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'birthday' => 'nullable|date',
        ], $this->profileValidationRules()));

        $validated['assigned_to'] = !empty($validated['assigned_to']) ? (int) $validated['assigned_to'] : null;
        $validated['client_status'] = !empty(trim($validated['client_status'] ?? '')) ? trim($validated['client_status']) : null;

        // Pre-flight check NIP. Dodatkowo: jeśli NIP po normalizacji jest IDENTYCZNY z istniejącym,
        // pomijamy go w UPDATE — MySQL/InnoDB ma edge-case z STORED GENERATED columns + UNIQUE
        // index (UPDATE z tą samą wartością może rzucić "Duplicate entry" konfliktując sam ze sobą).
        if (array_key_exists('nip', $validated)) {
            $newNorm = preg_replace('/[^0-9]/', '', (string) ($validated['nip'] ?? ''));
            $oldNorm = preg_replace('/[^0-9]/', '', (string) ($client->nip ?? ''));

            if ($newNorm === $oldNorm) {
                // NIP się nie zmienił — pomiń pole, nie ruszaj generated column
                unset($validated['nip']);
            } elseif (strlen($newNorm) === 10) {
                $duplicate = Client::whereRaw("REPLACE(REPLACE(REPLACE(nip, ' ', ''), '-', ''), '.', '') = ?", [$newNorm])
                    ->where('id', '!=', $client->id)
                    ->first();
                if ($duplicate) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'nip' => "Klient z tym NIP już istnieje w bazie: {$duplicate->name} (ID {$duplicate->id}).",
                    ]);
                }
            }
        }

        $oldValues = $client->toArray();

        try {
            $client->update($validated);
        } catch (\Throwable $e) {
            report($e);
            $msg = str_contains($e->getMessage(), 'Duplicate entry') && str_contains($e->getMessage(), 'nip_normalized')
                ? 'Klient z tym NIP już istnieje w bazie.'
                : 'Nie udało się zapisać zmian: ' . $e->getMessage();

            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }
            return back()->withErrors(['save' => $msg])->withInput();
        }

        ActivityLog::log('update', $client, "Zaktualizowano klienta: {$client->name}", $oldValues, $validated);

        $this->syncClientToRingostat($client);

        return redirect()->route('clients.index')
            ->with('success', 'Klient został zaktualizowany.');
    }

    /**
     * Usuń klienta (soft delete)
     */
    public function destroy(Client $client): RedirectResponse
    {
        $name = $client->name;
        
        ActivityLog::log('delete', $client, "Usunięto klienta: {$name}");
        
        $client->delete();

        return redirect()->route('clients.index')
            ->with('success', 'Klient został usunięty.');
    }

    /**
     * Eksport klientów do CSV
     */
    public function export(Request $request): StreamedResponse
    {
        $headers = [
            'Nazwa', 'Nazwa skrócona', 'Typ', 'NIP', 'REGON', 'Email', 'Telefon', 'Telefon 2',
            'Strona www', 'Ulica', 'Nr budynku', 'Nr lokalu', 'Kod pocztowy', 'Miasto', 'Kraj',
            'Osoba kontaktowa', 'Email kontaktowy', 'Telefon kontaktowy', 'Status', 'Notatki',
        ];

        return response()->streamDownload(function () use ($headers) {
            $handle = fopen('php://output', 'w');

            // BOM dla prawidłowego kodowania UTF-8 w Excelu
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, $headers, ';');

            // chunkById zamiast ->get() — kluczowe przy 10k+ klientów (OOM risk)
            Client::orderBy('id')->chunkById(1000, function ($clients) use ($handle) {
                foreach ($clients as $client) {
                    fputcsv($handle, [
                        $client->name,
                        $client->short_name,
                        $client->type === 'company' ? 'Firma' : 'Osoba prywatna',
                        $client->nip,
                        $client->regon,
                        $client->email,
                        $client->phone,
                        $client->phone2,
                        $client->website,
                        $client->street,
                        $client->building_number,
                        $client->apartment_number,
                        $client->postal_code,
                        $client->city,
                        $client->country,
                        $client->contact_person,
                        $client->contact_email,
                        $client->contact_phone,
                        $client->status === 'active' ? 'Aktywny' : ($client->status === 'inactive' ? 'Nieaktywny' : 'Potencjalny'),
                        $client->notes,
                    ], ';');
                }
                // flush do klienta co chunk — żeby przeglądarka dostawała dane streamingiem
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            });

            fclose($handle);
        }, 'klienci_' . date('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Import klientów z CSV
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        // Pomiń BOM jeśli istnieje
        $bom = fread($handle, 3);
        if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) {
            rewind($handle);
        }

        // Odczytaj nagłówek
        $header = fgetcsv($handle, 0, ';');
        if (!$header) {
            fclose($handle);
            return back()->with('error', 'Plik CSV jest pusty lub ma nieprawidłowy format.');
        }

        // Mapowanie nagłówków na pola modelu
        $fieldMap = [
            'Nazwa' => 'name',
            'Nazwa skrócona' => 'short_name',
            'Typ' => 'type',
            'NIP' => 'nip',
            'REGON' => 'regon',
            'Email' => 'email',
            'Telefon' => 'phone',
            'Telefon 2' => 'phone2',
            'Strona www' => 'website',
            'Ulica' => 'street',
            'Nr budynku' => 'building_number',
            'Nr lokalu' => 'apartment_number',
            'Kod pocztowy' => 'postal_code',
            'Miasto' => 'city',
            'Kraj' => 'country',
            'Osoba kontaktowa' => 'contact_person',
            'Email kontaktowy' => 'contact_email',
            'Telefon kontaktowy' => 'contact_phone',
            'Status' => 'status',
            'Notatki' => 'notes',
        ];

        // Zmapuj indeksy kolumn
        $columnMap = [];
        foreach ($header as $index => $col) {
            $col = trim($col);
            if (isset($fieldMap[$col])) {
                $columnMap[$index] = $fieldMap[$col];
            }
        }

        if (empty($columnMap)) {
            fclose($handle);
            return back()->with('error', 'Nie rozpoznano nagłówków CSV. Użyj pliku z eksportu lub nagłówków: Nazwa, Typ, Email, itd.');
        }

        $imported = 0;
        $skipped = 0;
        $row = 1;

        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $row++;
            $record = [];
            foreach ($columnMap as $index => $field) {
                $record[$field] = $data[$index] ?? null;
            }

            // Nazwa jest wymagana
            if (empty($record['name'])) {
                $skipped++;
                continue;
            }

            // Mapuj typ
            if (isset($record['type'])) {
                $record['type'] = match(mb_strtolower(trim($record['type']))) {
                    'firma', 'company' => 'company',
                    'osoba prywatna', 'osoba', 'person', 'individual' => 'person',
                    default => 'company',
                };
            } else {
                $record['type'] = 'company';
            }

            // Mapuj status
            if (isset($record['status'])) {
                $record['status'] = match(mb_strtolower(trim($record['status']))) {
                    'aktywny', 'active' => 'active',
                    'nieaktywny', 'inactive' => 'inactive',
                    'potencjalny', 'potential' => 'potential',
                    default => 'active',
                };
            } else {
                $record['status'] = 'active';
            }

            $record['created_by'] = auth()->id();

            // Wyczyść puste wartości
            $record = array_filter($record, fn($v) => $v !== null && $v !== '');
            $record['created_by'] = auth()->id();

            try {
                Client::create($record);
                $imported++;
            } catch (\Throwable $e) {
                $skipped++;
                \Log::warning('Client import: pominięto wiersz', [
                    'row_name' => $record['name'] ?? null,
                    'row_nip'  => $record['nip'] ?? null,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        fclose($handle);

        $message = "Zaimportowano {$imported} klientów.";
        if ($skipped > 0) {
            $message .= " Pominięto {$skipped} wierszy.";
        }

        return back()->with('success', $message);
    }

    /**
     * Bulk actions — masowe operacje na klientach
     */
    public function bulkAction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:clients,id',
            'action' => 'required|in:delete,change_status',
            'status' => 'required_if:action,change_status|nullable|in:active,inactive,potential',
        ]);

        $count = count($validated['ids']);

        switch ($validated['action']) {
            case 'delete':
                $clients = Client::whereIn('id', $validated['ids'])->get();
                foreach ($clients as $client) {
                    ActivityLog::log('delete', $client, "Bulk: usunięto klienta: {$client->name}");
                    $client->delete();
                }
                return back()->with('success', "Usunięto {$count} klientów.");

            case 'change_status':
                Client::whereIn('id', $validated['ids'])->update(['status' => $validated['status']]);
                $statusLabels = ['active' => 'Aktywny', 'inactive' => 'Nieaktywny', 'potential' => 'Potencjalny'];
                $label = $statusLabels[$validated['status']] ?? $validated['status'];
                ActivityLog::log('update', null, "Bulk: zmieniono status {$count} klientów na: {$label}");
                return back()->with('success', 'Zmieniono status ' . $count . ' klientów na: ' . $label . '.');
        }

        return back();
    }

    private function syncClientToRingostat(Client $client): void
    {
        if (empty($client->phone) && empty($client->phone2) && empty($client->contact_phone)) {
            return;
        }

        try {
            $service = app(RingostatService::class);
            // Play Wirtualna Centralka nie ma sync kontaktów (zastąpiło Ringostat).
            // Metoda syncContact() została usunięta — sprawdzamy defensywnie na wypadek przywrócenia.
            if (method_exists($service, 'syncContact')) {
                $service->syncContact($client);
            }
        } catch (\Throwable $e) {
            // Non-blocking — nie blokujemy requestu gdyby sync kiedykolwiek failnął
        }
    }
}
