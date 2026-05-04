<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Status;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatusController extends Controller
{
    /**
     * Lista statusów
     */
    public function index(): Response
    {
        $statuses = Status::withCount('tasks')
            ->ordered()
            ->get();

        return Inertia::render('Statuses/Index', [
            'statuses' => $statuses,
            'types' => Status::getTypes(),
            'colors' => Status::getColors(),
        ]);
    }

    /**
     * Formularz tworzenia
     */
    public function create(): Response
    {
        $maxOrder = Status::max('order') ?? 0;

        return Inertia::render('Statuses/Form', [
            'status' => null,
            'types' => Status::getTypes(),
            'colors' => Status::getColors(),
            'nextOrder' => $maxOrder + 1,
        ]);
    }

    /**
     * Zapisz nowy status
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:50|unique:statuses,slug',
            'type' => 'required|in:new,in_progress,done,cancelled',
            'color' => 'required|string|max:7',
            'order' => 'required|integer|min:0',
            'is_default' => 'boolean',
            'is_visible' => 'boolean',
            'is_final' => 'boolean',
            'description' => 'nullable|string',
        ]);

        // Jeśli nowy status ma być domyślny, usuń flagę z innych
        if ($validated['is_default'] ?? false) {
            Status::where('is_default', true)->update(['is_default' => false]);
        }

        $status = Status::create($validated);

        ActivityLog::log('create', $status, "Utworzono status: {$status->name}");

        return redirect()->route('statuses.index')
            ->with('success', 'Status został dodany.');
    }

    /**
     * Formularz edycji
     */
    public function edit(Status $status): Response
    {
        return Inertia::render('Statuses/Form', [
            'status' => $status,
            'types' => Status::getTypes(),
            'colors' => Status::getColors(),
        ]);
    }

    /**
     * Zaktualizuj status
     */
    public function update(Request $request, Status $status): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:50|unique:statuses,slug,' . $status->id,
            'type' => 'required|in:new,in_progress,done,cancelled',
            'color' => 'required|string|max:7',
            'order' => 'required|integer|min:0',
            'is_default' => 'boolean',
            'is_visible' => 'boolean',
            'is_final' => 'boolean',
            'description' => 'nullable|string',
        ]);

        // Jeśli nowy status ma być domyślny, usuń flagę z innych
        if (($validated['is_default'] ?? false) && !$status->is_default) {
            Status::where('is_default', true)->update(['is_default' => false]);
        }

        $oldValues = $status->toArray();
        $status->update($validated);

        ActivityLog::log('update', $status, "Zaktualizowano status: {$status->name}", $oldValues, $validated);

        return redirect()->route('statuses.index')
            ->with('success', 'Status został zaktualizowany.');
    }

    /**
     * Usuń status
     */
    public function destroy(Status $status): RedirectResponse
    {
        // Sprawdź czy status nie ma zadań
        if ($status->tasks()->count() > 0) {
            return redirect()->route('statuses.index')
                ->with('error', 'Nie można usunąć statusu, który ma przypisane zadania.');
        }

        $name = $status->name;
        
        ActivityLog::log('delete', $status, "Usunięto status: {$name}");
        
        $status->delete();

        return redirect()->route('statuses.index')
            ->with('success', 'Status został usunięty.');
    }

    /**
     * Aktualizuj kolejność statusów (drag & drop)
     */
    public function reorder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer|exists:statuses,id',
        ]);

        foreach ($validated['order'] as $index => $statusId) {
            Status::where('id', $statusId)->update(['order' => $index]);
        }

        return back()->with('success', 'Kolejność została zapisana.');
    }
}
