<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\User;
use Modules\Apilo\Services\ApiloService;
use Modules\Fakturownia\Services\FakturowniaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    protected FakturowniaService $fakturowniaService;

    public function __construct(FakturowniaService $fakturowniaService)
    {
        $this->fakturowniaService = $fakturowniaService;
    }
    /**
     * Lista użytkowników
     */
    public function index(Request $request): Response
    {
        $query = User::withCount(['assignedTasks', 'assignedTasks as active_tasks_count' => fn($q) => 
            $q->whereHas('status', fn($s) => $s->where('is_final', false))
        ]);

        // Filtrowanie
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->get('role')) {
            $query->where('role', $role);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Sortowanie
        $sortBy = $request->get('sort', 'name');
        $sortDir = $request->get('dir', 'asc');
        $query->orderBy($sortBy, $sortDir);

        $users = $query->paginate(15)->withQueryString();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => [
                'search' => $request->get('search', ''),
                'role' => $request->get('role', ''),
                'status' => $request->get('status', ''),
                'sort' => $sortBy,
                'dir' => $sortDir,
            ],
            'roles' => [
                'admin' => 'Administrator',
                'manager' => 'Manager',
                'user' => 'Użytkownik',
            ],
            'statuses' => [
                'active' => 'Aktywny',
                'inactive' => 'Nieaktywny',
            ],
        ]);
    }

    /**
     * Formularz tworzenia
     */
    public function create(): Response
    {
        return Inertia::render('Users/Form', [
            'user' => null,
            'roles' => [
                'admin' => 'Administrator',
                'manager' => 'Manager',
                'user' => 'Użytkownik',
            ],
            'statuses' => [
                'active' => 'Aktywny',
                'inactive' => 'Nieaktywny',
            ],
            'permissions' => Permission::grouped(),
            'fakturowniaDepartments' => $this->fakturowniaService->getDepartments(),
            'apiloPlatforms' => $this->apiloPlatformsForUserForm(),
        ]);
    }

    /**
     * Zapisz nowego użytkownika
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email|max:255',
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:255',
            'role' => 'required|in:admin,manager,user',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
            'fakturownia_department_id' => 'nullable|integer',
            'fakturownia_department_name' => 'nullable|string|max:255',
            'apilo_default_platform_id' => 'nullable|numeric',
            'play_phone' => 'nullable|string|max:30',
        ]);

        $permissions = $validated['permissions'] ?? [];
        unset($validated['permissions']);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);

        // Przypisz uprawnienia (jeśli nie admin)
        if ($validated['role'] !== 'admin' && !empty($permissions)) {
            $user->permissions()->sync($permissions);
        }

        ActivityLog::log('create', $user, "Utworzono użytkownika: {$user->name}");

        return redirect()->route('users.index')
            ->with('success', 'Użytkownik został dodany.');
    }

    /**
     * Pokaż szczegóły użytkownika
     */
    public function show(User $user): Response
    {
        $user->load(['permissions', 'assignedTasks' => fn($q) => $q->with(['status', 'client'])->incomplete()->limit(10)]);

        return Inertia::render('Users/Show', [
            'user' => $user,
        ]);
    }

    /**
     * Formularz edycji
     */
    public function edit(User $user): Response
    {
        $user->load(['permissions', 'managedCalendars']);

        return Inertia::render('Users/Form', [
            'user' => $user,
            'userPermissions' => $user->permissions->pluck('id')->toArray(),
            'managedCalendarIds' => $user->managedCalendars->pluck('id')->toArray(),
            'availableCalendarUsers' => User::where('id', '!=', $user->id)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'roles' => [
                'admin' => 'Administrator',
                'manager' => 'Manager',
                'user' => 'Użytkownik',
            ],
            'statuses' => [
                'active' => 'Aktywny',
                'inactive' => 'Nieaktywny',
            ],
            'permissions' => Permission::grouped(),
            'fakturowniaDepartments' => $this->fakturowniaService->getDepartments(),
            'apiloPlatforms' => $this->apiloPlatformsForUserForm(),
        ]);
    }

    /**
     * Zaktualizuj użytkownika
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id . '|max:255',
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'phone' => 'nullable|string|max:20',
            'position' => 'nullable|string|max:255',
            'role' => 'required|in:admin,manager,user',
            'status' => 'required|in:active,inactive',
            'notes' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
            'managed_calendars' => 'nullable|array',
            'managed_calendars.*' => 'integer|exists:users,id',
            'fakturownia_department_id' => 'nullable|integer',
            'fakturownia_department_name' => 'nullable|string|max:255',
            'apilo_default_platform_id' => 'nullable|numeric',
            'play_phone' => 'nullable|string|max:30',
        ]);

        $permissions = $validated['permissions'] ?? [];
        $managedCalendars = array_values(array_filter(
            $validated['managed_calendars'] ?? [],
            fn($cid) => (int) $cid !== (int) $user->id
        ));
        unset($validated['permissions'], $validated['managed_calendars']);

        // Aktualizuj hasło tylko jeśli podano
        if (empty($validated['password'])) {
            unset($validated['password']);
        } else {
            $validated['password'] = Hash::make($validated['password']);
        }

        $oldValues = $user->toArray();
        $user->update($validated);

        // Zaktualizuj uprawnienia (jeśli nie admin)
        if ($validated['role'] !== 'admin') {
            $user->permissions()->sync($permissions);
        } else {
            $user->permissions()->detach();
        }

        $user->managedCalendars()->sync($managedCalendars);

        ActivityLog::log('update', $user, "Zaktualizowano użytkownika: {$user->name}", $oldValues, $validated);

        return redirect()->route('users.index')
            ->with('success', 'Użytkownik został zaktualizowany.');
    }

    /**
     * Upload avatara użytkownika
     */
    public function updateAvatar(Request $request, User $user): RedirectResponse
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        // Usuń stary avatar
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar' => $path]);

        ActivityLog::log('update', $user, "Zaktualizowano avatar użytkownika: {$user->name}");

        return redirect()->back()->with('success', 'Avatar został zaktualizowany.');
    }

    /**
     * Usuń avatar użytkownika
     */
    public function deleteAvatar(User $user): RedirectResponse
    {
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->update(['avatar' => null]);
            ActivityLog::log('update', $user, "Usunięto avatar użytkownika: {$user->name}");
        }

        return redirect()->back()->with('success', 'Avatar został usunięty.');
    }

    /**
     * Usuń użytkownika
     */
    public function destroy(User $user): RedirectResponse
    {
        // Nie pozwól usunąć siebie
        if ($user->id === auth()->id()) {
            return redirect()->route('users.index')
                ->with('error', 'Nie możesz usunąć własnego konta.');
        }

        // Nie pozwól usunąć ostatniego admina
        if ($user->isAdmin() && User::where('role', 'admin')->count() <= 1) {
            return redirect()->route('users.index')
                ->with('error', 'Nie można usunąć ostatniego administratora.');
        }

        $name = $user->name;
        
        ActivityLog::log('delete', $user, "Usunięto użytkownika: {$name}");
        
        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'Użytkownik został usunięty.');
    }

    /**
     * Kanały sprzedaży Apilo do przypisania użytkownikowi (formularz)
     *
     * @return array<int, array{id: mixed, name: string}>
     */
    protected function apiloPlatformsForUserForm(): array
    {
        try {
            $opts = app(ApiloService::class)->getOrderOptions();

            return $opts['platforms'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
