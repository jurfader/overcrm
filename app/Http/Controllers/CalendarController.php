<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientVisit;
use App\Models\EmailTemplate;
use App\Models\Status;
use Modules\Apilo\Services\ApiloService;
use Modules\Fakturownia\Services\FakturowniaService;
use App\Services\GusService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CalendarController extends Controller
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
     * Czy user widzi/edytuje wizytę przypisaną do innego usera?
     * Tak, jeśli: wizyta jego, jest adminem, albo ma kalendarz w calendar_managers.
     */
    private function canAccessVisit(\App\Models\User $user, ClientVisit $visit): bool
    {
        if ($visit->user_id === $user->id) return true;
        if ($user->hasAdminRights()) return true;
        return $user->managedCalendars()->where('users.id', $visit->user_id)->exists();
    }

    public function index(Request $request)
    {
        $year = $request->get('year', now()->year);
        $month = $request->get('month', now()->month);
        $view = $request->get('view', 'month'); // month, week, day

        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        // Widok tygodniowy/dzienny: pobierz też dni z sąsiednich miesięcy (tydzień może obejmować 2 miesiące)
        if ($view === 'week' || $view === 'day') {
            $startDate = $startDate->copy()->subDays(7);
            $endDate = $endDate->copy()->addDays(7);
        }

        // Pobierz wizyty na dany miesiąc - filtruj po użytkowniku
        $user = auth()->user();
        $managedCalendarIds = $user->managedCalendars()->pluck('users.id')->all();
        $canSelectUser = $user->hasAdminRights() || !empty($managedCalendarIds);
        $selectedUserId = null;
        $showAll = false;

        if ($canSelectUser && $request->filled('user_id')) {
            $requested = $request->get('user_id');
            if ($requested === 'all' && $user->hasAdminRights()) {
                $showAll = true;
            } elseif (is_numeric($requested)) {
                $requestedUserId = (int) $requested;
                $allowed = $user->hasAdminRights()
                    || $requestedUserId === $user->id
                    || in_array($requestedUserId, $managedCalendarIds, true);
                if ($allowed && \App\Models\User::where('id', $requestedUserId)->exists()) {
                    $selectedUserId = $requestedUserId;
                }
            }
        }

        $trashed = $request->boolean('trashed');

        // Otwórz wizytę z linku (np. z karty klienta)
        if ($visitId = $request->get('openVisit')) {
            $request->session()->put('openedVisitId', (int) $visitId);
        }

        $visitsQuery = ClientVisit::with(['client', 'user', 'status'])
            ->whereBetween('visit_date', [$startDate, $endDate]);

        if ($trashed) {
            $visitsQuery->onlyTrashed();
        }

        if (! $showAll) {
            $filterUserId = $selectedUserId ?? $user->id;
            $visitsQuery->where('user_id', $filterUserId);
        }

        $visits = $visitsQuery
            ->orderBy('visit_date')
            ->orderBy('visit_time')
            ->get()
            ->groupBy(function ($visit) {
                return $visit->visit_date->format('Y-m-d');
            });

        // Pobierz klientów do dropdown (z emailem do wysyłki)
        $clients = Client::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'nip', 'email']);

        // Pobierz użytkowników do przypisania
        $users = \App\Models\User::orderBy('name')
            ->get(['id', 'name']);

        // Pobierz aktywne szablony email
        $emailTemplates = [];
        if (Schema::hasTable('email_templates')) {
            $emailTemplates = EmailTemplate::where('is_active', true)
                ->orderBy('category')
                ->orderBy('name')
                ->get(['id', 'name', 'description', 'category', 'is_active']);
        }

        // Pobierz konfiguracje SMTP użytkownika
        $mailConfigs = [];
        if (Schema::hasTable('user_mail_configs')) {
            $mailConfigs = auth()->user()->mailConfigs()
                ->get(['id', 'name', 'mail_from_address', 'is_default', 'is_verified']);
        }

        // Pobierz aktywne cenniki (do załączania PDF przy emailach)
        $priceLists = \App\Models\PriceList::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        // Pobierz statusy
        $statuses = Status::visible()->ordered()->get(['id', 'name', 'color', 'type', 'is_default']);

        return Inertia::render('Calendar/Index', [
            'year' => (int) $year,
            'month' => (int) $month,
            'view' => $view,
            'trashed' => $trashed,
            'visits' => $visits,
            'openedVisitId' => $request->session()->pull('openedVisitId'),
            'clients' => $clients,
            'users' => $users,
            'emailTemplates' => $emailTemplates,
            'mailConfigs' => $mailConfigs,
            'priceLists' => $priceLists,
            'statuses' => $statuses,
            'startDate' => $startDate->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'selectedUserId' => $selectedUserId,
            'showAll' => $showAll,
            'canSelectUser' => $canSelectUser,
            'managedCalendarIds' => $managedCalendarIds,
            'isAdminCalendar' => $user->hasAdminRights(),
            'profileOptions' => $this->profileOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge([
            'client_id' => $request->filled('client_id') ? $request->client_id : null,
        ]);

        if ($request->input('visit_time') === '' || $request->input('visit_time') === null) {
            $request->merge(['visit_time' => null]);
        }

        // visit_time: akceptuj HH:MM i HH:MM:SS
        $visitTime = $request->visit_time;
        if (is_string($visitTime) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $visitTime)) {
            $request->merge(['visit_time' => substr($visitTime, 0, 5)]);
        }

        $validated = $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'visit_date' => 'required|date',
            'visit_time' => 'nullable|date_format:H:i',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'phones' => 'nullable|array',
            'phones.*' => 'nullable|string|max:30',
            'link' => 'nullable|string|max:500',
            'website' => 'nullable|string|max:500',
            'color' => 'nullable|string|max:7',
            'status' => 'nullable|exists:statuses,id',
            'deadline' => 'nullable|date',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $authUser = auth()->user();
        if (! isset($validated['user_id'])) {
            $validated['user_id'] = $authUser->id;
        } elseif ((int) $validated['user_id'] !== (int) $authUser->id && ! $authUser->hasAdminRights()) {
            // Non-admin może przypisać wizytę innemu userowi tylko jeśli ma calendar_managers
            $managedIds = $authUser->managedCalendars()->pluck('users.id')->all();
            if (! in_array((int) $validated['user_id'], $managedIds, true)) {
                abort(403, 'Brak uprawnień do tworzenia wizyty na tym kalendarzu');
            }
        }

        if (! empty($validated['status'])) {
            $validated['status_id'] = $validated['status'];
        }
        unset($validated['status']);

        // Jeśli brak tytułu a jest klient – ustaw nazwę klienta
        if (empty(trim($validated['title'] ?? '')) && ! empty($validated['client_id'])) {
            $client = \App\Models\Client::find($validated['client_id']);
            $validated['title'] = $client?->name ?? null;
        }

        // Telefony wizyty: ręczne + wyciągnięte regexem z description
        $validated['phones'] = $this->processVisitPhones(
            $validated['phones'] ?? [],
            $validated['description'] ?? ''
        );
        $validated['phones_normalized'] = ClientVisit::buildNormalizedPhones($validated['phones']);

        $visit = ClientVisit::create($validated);

        // Sync telefonów wizyty → klient (jeśli przypisany)
        if ($visit->client_id && !empty($validated['phones'])) {
            $this->syncPhonesToClient($visit->client_id, $validated['phones']);
        }

        ActivityLog::log('create', $visit, 'Dodano wizytę: '.($visit->client?->name ?? $visit->title ?? 'Wizyta'));

        $params = array_filter([
            'year' => $request->get('year') ?: $visit->visit_date->format('Y'),
            'month' => $request->get('month') ?: $visit->visit_date->format('n'),
            'view' => $request->get('view'),
            'user_id' => $request->get('user_id'),
        ]);

        $redirect = redirect()->route('calendar.index', $params)->with('success', 'Wizyta została dodana');
        if ($request->boolean('open_after_create')) {
            $redirect->with('openedVisitId', $visit->id);
        }

        return $redirect;
    }

    public function update(Request $request, ClientVisit $visit)
    {
        if (! $this->canAccessVisit(auth()->user(), $visit)) {
            abort(403, 'Brak uprawnień do edycji tej wizyty');
        }

        // KRYTYCZNE: Tylko normalizuj client_id jeśli pole zostało przekazane w request.
        // Drag-drop wizyty na kalendarzu wysyła TYLKO {visit_date, deadline, visit_time} —
        // bez client_id. Wcześniejsze ślepe merge() ustawiało client_id na null → odpinało
        // klienta od wizyty po przeniesieniu.
        if ($request->exists('client_id')) {
            $request->merge([
                'client_id' => $request->filled('client_id') ? $request->client_id : null,
            ]);
        }

        if ($request->exists('visit_time') && ($request->input('visit_time') === '' || $request->input('visit_time') === null)) {
            $request->merge(['visit_time' => null]);
        }

        // visit_time: akceptuj HH:MM i HH:MM:SS (baza może zwracać z sekundami)
        $visitTime = $request->visit_time;
        if (is_string($visitTime) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $visitTime)) {
            $request->merge(['visit_time' => substr($visitTime, 0, 5)]); // HH:MM
        }

        $validated = $request->validate([
            'client_id' => 'sometimes|nullable|exists:clients,id',
            'visit_date' => 'sometimes|date',
            'visit_time' => 'nullable|date_format:H:i',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'phones' => 'nullable|array',
            'phones.*' => 'nullable|string|max:30',
            'link' => 'nullable|string|max:500',
            'website' => 'nullable|string|max:500',
            'color' => 'nullable|string|max:7',
            'status' => 'nullable|exists:statuses,id',
            'deadline' => 'nullable|date',
            'order_value' => 'nullable|numeric|min:0',
            'user_id' => 'nullable|exists:users,id',
        ]);

        // Upewnij się, że description i notes są w update (mogą być puste – request może ich nie zawierać)
        $validated['description'] = $request->input('description', $visit->description ?? '');
        $validated['notes'] = $request->input('notes', $visit->notes ?? '');

        if (! empty($validated['status'])) {
            $validated['status_id'] = $validated['status'];
        }
        unset($validated['status']);

        // Jeśli brak tytułu a jest klient – ustaw nazwę klienta
        $clientId = $validated['client_id'] ?? $visit->client_id;
        if (! empty($clientId) && empty(trim($validated['title'] ?? $visit->title ?? ''))) {
            $client = \App\Models\Client::find($clientId);
            $validated['title'] = $client?->name ?? null;
        }

        // Telefony: ręczne + wyciągnięte z description (tylko jeśli request zawiera phones)
        if ($request->has('phones') || $request->has('description')) {
            $validated['phones'] = $this->processVisitPhones(
                $request->input('phones', $visit->phones ?? []),
                $validated['description'] ?? ''
            );
            $validated['phones_normalized'] = ClientVisit::buildNormalizedPhones($validated['phones']);
        }

        $oldValues = $visit->toArray();
        $visit->update($validated);

        // Sync telefonów wizyty → klient (jeśli przypisany i phones są)
        if ($clientId && !empty($validated['phones'] ?? null)) {
            $this->syncPhonesToClient($clientId, $validated['phones']);
        }

        ActivityLog::log(
            'update',
            $visit,
            'Zaktualizowano wizytę: '.($visit->client?->name ?? $visit->title ?? 'Wizyta'),
            $oldValues,
            $validated,
        );

        return back()->with('success', 'Wizyta została zaktualizowana');
    }

    public function destroy(ClientVisit $visit)
    {
        if (! $this->canAccessVisit(auth()->user(), $visit)) {
            abort(403, 'Brak uprawnień do usunięcia tej wizyty');
        }

        $title = $visit->client?->name ?? $visit->title ?? 'Wizyta';
        ActivityLog::log('delete', $visit, 'Usunięto wizytę: '.$title);

        $visit->delete();

        return back()->with('success', 'Wizyta została przeniesiona do kosza.');
    }

    public function restore(int $id)
    {
        $visit = ClientVisit::onlyTrashed()->findOrFail($id);
        $title = $visit->client?->name ?? $visit->title ?? 'Wizyta';
        $visit->restore();

        ActivityLog::log('restore', $visit, 'Przywrócono wizytę: '.$title);

        return back()->with('success', 'Wizyta została przywrócona.');
    }

    public function forceDelete(int $id)
    {
        $visit = ClientVisit::onlyTrashed()->findOrFail($id);
        $title = $visit->client?->name ?? $visit->title ?? 'Wizyta';
        $visit->forceDelete();

        ActivityLog::log('delete', null, 'Trwale usunięto wizytę: '.$title);

        return redirect()->route('calendar.index', ['trashed' => true])
            ->with('success', 'Wizyta została trwale usunięta.');
    }

    /**
     * Wyszukiwanie wizyt i klientów (JSON) – kalendarz, Ctrl+K
     */
    public function visitsSearch(Request $request)
    {
        $q = $request->get('q', '');
        $limit = min((int) $request->get('limit', 15), 30);
        $includeClients = $request->boolean('clients', true);

        $user = auth()->user();
        $query = ClientVisit::with(['client', 'user', 'status'])
            ->orderBy('visit_date', 'desc')
            ->orderBy('visit_time', 'desc')
            ->limit($limit);

        $filterUserId = $user->id;
        if ($user->hasAdminRights() && $request->filled('user_id')) {
            if ($request->get('user_id') !== 'all' && is_numeric($request->get('user_id'))) {
                $filterUserId = (int) $request->get('user_id');
            } elseif ($request->get('user_id') === 'all') {
                $filterUserId = null; // bez filtra = wszystkie
            }
        }
        if ($filterUserId !== null) {
            $query->where('user_id', $filterUserId);
        }

        if (strlen($q) >= 2) {
            $query->where(function ($qry) use ($q) {
                $qry->where('title', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%")
                    ->orWhereHas('client', function ($c) use ($q) {
                        $c->where('name', 'like', "%{$q}%")
                            ->orWhere('short_name', 'like', "%{$q}%")
                            ->orWhere('nip', 'like', "%{$q}%");
                    });
            });
        }

        $visits = $query->get();

        $clients = collect();
        if ($includeClients && strlen($q) >= 2) {
            $clients = Client::where('status', 'active')
                ->where(function ($c) use ($q) {
                    $c->where('name', 'like', "%{$q}%")
                        ->orWhere('short_name', 'like', "%{$q}%")
                        ->orWhere('nip', 'like', "%{$q}%");
                })
                ->orderBy('name')
                ->limit(10)
                ->get(['id', 'name', 'short_name', 'nip', 'city']);
        }

        return response()->json([
            'visits' => $visits,
            'clients' => $clients,
        ]);
    }

    /**
     * Pełny kontekst wizyty – do wyświetlenia w floating panel (ClientModal)
     */
    public function visitContext(ClientVisit $visit)
    {
        $user = auth()->user();
        if (! $this->canAccessVisit($user, $visit)) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $visit->load(['client', 'user', 'status']);

        $clients = Client::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'nip', 'email']);

        $users = \App\Models\User::orderBy('name')->get(['id', 'name']);

        $emailTemplates = [];
        if (Schema::hasTable('email_templates')) {
            $emailTemplates = EmailTemplate::where('is_active', true)
                ->orderBy('category')->orderBy('name')
                ->get(['id', 'name', 'description', 'category', 'is_active']);
        }

        $mailConfigs = [];
        if (Schema::hasTable('user_mail_configs')) {
            $mailConfigs = auth()->user()->mailConfigs()
                ->get(['id', 'name', 'mail_from_address', 'is_default', 'is_verified']);
        }

        $priceLists = \App\Models\PriceList::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $statuses = Status::visible()->ordered()->get(['id', 'name', 'color', 'type', 'is_default']);

        // Fakturownia i Apilo – zwracamy puste; ClientModal pobierze je w tle (loadClientData)
        // Dzięki temu opis wizyty ładuje się od razu, bez czekania na zewnętrzne API
        $invoices = [];
        $orders = [];

        return response()->json([
            'visit' => $visit,
            'clients' => $clients,
            'users' => $users,
            'emailTemplates' => $emailTemplates,
            'mailConfigs' => $mailConfigs,
            'priceLists' => $priceLists,
            'statuses' => $statuses,
            'profileOptions' => $this->profileOptions(),
            'invoices' => $invoices,
            'orders' => $orders,
        ]);
    }

    public function show(ClientVisit $visit)
    {
        $visit->load(['client', 'user']);

        $invoices = [];
        $orders = [];

        try {
            // Pobierz faktury z Fakturowni dla klienta
            if ($visit->client?->nip) {
                $fakturownia = app(FakturowniaService::class);
                $invoices = $fakturownia->getInvoicesForClient($visit->client->nip);
            }

            // Pobierz zamówienia z Apilo dla klienta
            if ($visit->client_id) {
                $apilo = app(ApiloService::class);
                $orders = $apilo->getOrdersForClient((int) $visit->client_id);
            }
        } catch (\Throwable $e) {
            \Log::warning('Calendar show: external API error', [
                'visit_id' => $visit->id,
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'visit' => $visit,
            'invoices' => $invoices,
            'orders' => $orders,
        ]);
    }

    public function invoicesByNip(Request $request)
    {
        $nip = \Modules\Fakturownia\Services\FakturowniaService::normalizeNip($request->get('nip', ''));
        if (strlen($nip) < 10) {
            return response()->json(['invoices' => []]);
        }

        try {
            $fakturownia = app(FakturowniaService::class);
            $invoices = $fakturownia->getInvoicesForClient($nip);

            return response()->json(['invoices' => $invoices]);
        } catch (\Throwable $e) {
            \Log::warning('invoicesByNip error', ['message' => $e->getMessage()]);

            return response()->json(['invoices' => []]);
        }
    }

    public function invoiceDetail(int $id)
    {
        try {
            $fakturownia = app(FakturowniaService::class);
            $invoice = $fakturownia->getInvoice($id);

            return response()->json($invoice ?: ['error' => 'Nie znaleziono faktury']);
        } catch (\Throwable $e) {
            \Log::warning('invoiceDetail error', ['id' => $id, 'message' => $e->getMessage()]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function invoicePdf(int $id)
    {
        try {
            $fakturownia = app(FakturowniaService::class);
            $base64 = $fakturownia->getInvoicePdf($id);

            if (!$base64) {
                return response()->json(['error' => 'Nie udało się pobrać PDF faktury'], 404);
            }

            $pdf = base64_decode($base64);

            return response($pdf)
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'inline; filename="faktura-' . $id . '.pdf"');
        } catch (\Throwable $e) {
            \Log::warning('invoicePdf error', ['id' => $id, 'message' => $e->getMessage()]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Pobierz linki do śledzenia przesyłek zamówienia Apilo
     */
    public function orderTracking(string $orderId)
    {
        try {
            $apilo = app(ApiloService::class);
            $links = $apilo->getOrderTrackingLinks($orderId);

            return response()->json(['links' => $links]);
        } catch (\Throwable $e) {
            \Log::warning('orderTracking error', ['orderId' => $orderId, 'message' => $e->getMessage()]);

            return response()->json(['links' => []], 500);
        }
    }

    public function lookupNip(Request $request)
    {
        $request->validate([
            'nip'           => 'required|string|min:10|max:13',
            'skip_existing' => 'nullable|boolean',
        ]);

        $nip = preg_replace('/[^0-9]/', '', $request->nip);

        if (strlen($nip) !== 10) {
            return response()->json([
                'success' => false,
                'message' => 'NIP musi mieć 10 cyfr',
            ], 422);
        }

        if (!GusService::validateNip($nip)) {
            return response()->json([
                'success' => false,
                'message' => 'Nieprawidłowy NIP — błędna suma kontrolna',
            ], 422);
        }

        // skip_existing=1 → zawsze pobiera świeże dane z GUS (dla formularza zamówienia/bilingu)
        // domyślnie sprawdza duplikaty w bazie (dla dodawania klienta)
        if (!$request->boolean('skip_existing')) {
            $existingClient = Client::whereRaw("REPLACE(REPLACE(REPLACE(nip, ' ', ''), '-', ''), '.', '') = ?", [$nip])
                ->first(['id', 'name', 'short_name', 'nip', 'regon', 'type', 'email', 'phone', 'street', 'building_number', 'city', 'postal_code']);

            if ($existingClient) {
                return response()->json([
                    'success'         => true,
                    'existing_client' => $existingClient,
                ]);
            }
        }

        $gus = app(GusService::class);
        $data = $gus->getByNip($nip);

        if ($data) {
            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Nie znaleziono firmy o podanym NIP w rejestrach GUS/VAT',
        ], 404);
    }

    /**
     * Pobierz produkty TH_* — Fakturownia (priorytet, aktualne ceny) lub Apilo (fallback)
     */
    public function getProducts(Request $request)
    {
        $forceRefresh = $request->boolean('refresh');

        $fakturownia = app(FakturowniaService::class);
        if ($fakturownia->isConfigured()) {
            $products = $fakturownia->getProducts('TH_', $forceRefresh);
        } else {
            $apilo    = app(ApiloService::class);
            $products = $apilo->getProducts([], 'TH_', $forceRefresh);
        }

        return response()->json([
            'success'  => true,
            'products' => $products,
        ]);
    }

    /**
     * Pobierz opcje Apilo (platformy, płatności, dostawy) oraz domyślne wartości dla zalogowanego użytkownika
     */
    public function getApiloOptions(Request $request)
    {
        $apilo = app(ApiloService::class);

        try {
            $options = $apilo->getOrderOptions();
            $defaults = [
                'platform_id' => null,
                'payment_type_id' => null,
            ];
            $user = $request->user();
            if ($user && $user->apilo_default_platform_id !== null) {
                $want = $user->apilo_default_platform_id;
                foreach ($options['platforms'] ?? [] as $p) {
                    if ((string) ($p['id'] ?? '') === (string) $want) {
                        $defaults['platform_id'] = $p['id'];
                        break;
                    }
                }
            }
            $codId = $apilo->findFirstCodPaymentTypeId($options['payment_types'] ?? []);
            if ($codId !== null && $codId !== '') {
                $defaults['payment_type_id'] = $codId;
            }
            $options['defaults'] = $defaults;

            return response()->json($options);
        } catch (\Exception $e) {
            return response()->json([
                'platforms' => [],
                'payment_types' => [],
                'carriers' => [],
                'defaults' => [
                    'platform_id' => null,
                    'payment_type_id' => null,
                ],
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function updateClient(Request $request, Client $client)
    {
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
            'notes' => 'nullable|string',
            'birthday' => 'nullable|date',
        ], $this->profileValidationRules()));

        // KRYTYCZNE: Jeśli NIP po normalizacji jest IDENTYCZNY z istniejącym, NIE WYSYŁAJ
        // go w UPDATE. MySQL/InnoDB ma znany edge-case z STORED GENERATED columns + UNIQUE
        // index — przy UPDATE z tą samą wartością może rzucić "Duplicate entry" konfliktując
        // sam ze sobą. Jednocześnie pre-flight sprawdza konflikt z INNYM klientem.
        if (array_key_exists('nip', $validated)) {
            $newNorm = preg_replace('/[^0-9]/', '', (string) ($validated['nip'] ?? ''));
            $oldNorm = preg_replace('/[^0-9]/', '', (string) ($client->nip ?? ''));

            if ($newNorm === $oldNorm) {
                // NIP się nie zmienił — pomiń pole, nie ruszaj generated column
                unset($validated['nip']);
            } elseif (strlen($newNorm) >= 7) {
                // Pre-flight: czy inny aktywny klient już używa tego NIP?
                $conflict = Client::whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(nip, ' ', ''), '-', ''), '.', ''), '\t', '') = ?", [$newNorm])
                    ->where('id', '!=', $client->id)
                    ->first();
                if ($conflict) {
                    return response()->json([
                        'success' => false,
                        'message' => "Tego NIP-u używa już inny klient: „{$conflict->name}\" (ID {$conflict->id}). Sprawdź czy to nie jest ten sam klient — jeśli tak, scal duplikat poleceniem clients:merge.",
                    ], 422);
                }
            }
        }

        try {
            $oldValues = $client->toArray();
            $client->update($validated);

            ActivityLog::log('update', $client, "Zaktualizowano klienta z kalendarza: {$client->name}", $oldValues, $validated);

            $fresh = $client->fresh();
            \Log::info('Client updated from calendar', [
                'id' => $client->id,
                'address_saved' => [
                    'street' => $fresh->street,
                    'building_number' => $fresh->building_number,
                    'apartment_number' => $fresh->apartment_number,
                    'postal_code' => $fresh->postal_code,
                    'city' => $fresh->city,
                ],
            ]);

            return response()->json([
                'success' => true,
                'client' => $fresh,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Client update failed', ['id' => $client->id, 'error' => $e->getMessage()]);

            // Niepotrzebnie nie leakujemy SQL/nazw kolumn. Translate najczęstszych błędów.
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate entry') && str_contains($msg, 'nip_normalized')) {
                $userMsg = 'Inny klient w bazie ma już ten NIP. Sprawdź duplikaty (Klienci → wyszukaj po NIP) lub scal je w jednym.';
            } else {
                $userMsg = 'Nie udało się zapisać klienta. Spróbuj ponownie lub odśwież stronę.';
            }

            return response()->json([
                'success' => false,
                'message' => $userMsg,
            ], 500);
        }
    }

    public function createApiloOrder(Request $request, ClientVisit $visit)
    {
        $request->validate([
            'products' => 'required|array|min:1',
            'products.*.name' => 'required|string|max:255',
            'products.*.quantity' => 'required|numeric|min:1',
            'products.*.price' => 'required|numeric|min:0',
            'products.*.product_id' => 'nullable',
            'products.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'customer' => 'nullable|array',
            'customer.name' => 'nullable|string|max:255',
            'customer.nip' => 'nullable|string|max:50',
            'customer.street' => 'nullable|string|max:255',
            'customer.street_number' => 'nullable|string|max:50',
            'customer.zip' => 'nullable|string|max:20',
            'customer.city' => 'nullable|string|max:255',
            'customer.phone' => 'nullable|string|max:50',
            'customer.email' => 'nullable|string|max:255',
            'delivery' => 'nullable|array',
            'delivery.name' => 'nullable|string|max:255',
            'delivery.street' => 'nullable|string|max:255',
            'delivery.street_number' => 'nullable|string|max:50',
            'delivery.zip' => 'nullable|string|max:20',
            'delivery.city' => 'nullable|string|max:255',
            'delivery.phone' => 'nullable|string|max:50',
            'delivery.email' => 'nullable|string|max:255',
            'delivery.inpost_parcel_point' => 'nullable|string|max:20',
            'delivery.inpost_parcel_address' => 'nullable|string|max:255',
            'order_date' => 'nullable|date',
            'order_time' => 'nullable|date_format:H:i',
            'platform_id' => 'nullable',
            'payment_type' => 'nullable',
            'carrier_account' => 'nullable',
        ]);

        $apilo = app(ApiloService::class);

        // Blokada Fakturownia: zaległe przelewy (z karencją po terminie) albo nieopłacone pobranie przy zamówieniu za pobraniem
        $visit->loadMissing('client');
        $nip = $visit->client?->nip ? trim((string) $visit->client->nip) : null;
        $paymentIsCod = $apilo->isPaymentTypeLikelyCod($request->input('payment_type'));
        if ($nip && strlen(preg_replace('/\D/', '', $nip)) >= 10) {
            try {
                $fakturownia = app(FakturowniaService::class);
                $stats = $fakturownia->getClientPaymentStats($nip);
                if (! $paymentIsCod && ($stats['overdue'] ?? 0) > 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Nie można dodać zamówienia – klient ma nieopłacone faktury z przekroczonym terminem płatności (powyżej dopuszczalnej karencji). Proszę uregulować zaległości przed dodaniem nowego zamówienia.',
                    ], 422);
                }
            } catch (\Throwable $e) {
                \Log::warning('createApiloOrder: Fakturownia check failed', ['visit_id' => $visit->id, 'message' => $e->getMessage()]);
                // W razie błędu API nie blokujemy – pozwalamy na zamówienie
            }
        }

        try {
            $delivery = $request->delivery ?? [];
            $parcelPoint = $delivery['inpost_parcel_point'] ?? null;
            if (! empty($parcelPoint)) {
                $delivery['inpost_parcel_point'] = strtoupper(trim($parcelPoint));
            }
            $order = $apilo->createOrder([
                'client' => $visit->client,
                'products' => $request->products,
                'customer' => $request->customer,
                'delivery' => $delivery,
                'order_date' => $request->order_date,
                'order_time' => $request->order_time,
                'visit_time' => $visit->visit_time,
                'platform_id' => $request->platform_id,
                'payment_type' => $request->payment_type,
                'carrier_account' => $request->carrier_account,
            ]);

            if ($order) {
                $visit->update([
                    'apilo_order_id' => $order['id'] ?? null,
                    'order_value' => $order['total'] ?? 0,
                ]);

                return response()->json([
                    'success' => true,
                    'order' => $order,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Nie udało się utworzyć zamówienia w Apilo',
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Składa listę telefonów wizyty z ręcznych wpisów. Dedupliakcja po znormalizowanym numerze.
     */
    private function processVisitPhones(array $manual, string $description = ''): array
    {
        $all = [];
        $seen = [];

        foreach ($manual as $phone) {
            $trimmed = trim((string) $phone);
            if ($trimmed === '') continue;
            $key = ClientVisit::normalizePhone($trimmed);
            if (strlen($key) < 7 || isset($seen[$key])) continue;
            $seen[$key] = true;
            $all[] = $trimmed;
        }

        return $all;
    }

    /**
     * Dopisuje telefony wizyty do klienta (phone → phone2 → contact_phone).
     * Nie duplikuje numerów które klient już ma (po normalizacji).
     */
    private function syncPhonesToClient(int $clientId, array $phones): void
    {
        if (empty($phones)) return;

        $client = Client::find($clientId);
        if (!$client) return;

        $existing = [];
        foreach (['phone', 'phone2', 'contact_phone'] as $slot) {
            if (!empty($client->$slot)) {
                $existing[ClientVisit::normalizePhone($client->$slot)] = true;
            }
        }

        $patches = [];
        foreach ($phones as $phone) {
            $key = ClientVisit::normalizePhone($phone);
            if (isset($existing[$key])) continue;

            foreach (['phone', 'phone2', 'contact_phone'] as $slot) {
                if (empty($client->$slot) && !isset($patches[$slot])) {
                    $patches[$slot] = $phone;
                    $existing[$key] = true;
                    break;
                }
            }
        }

        if (!empty($patches)) {
            $client->update($patches);
        }
    }
}
