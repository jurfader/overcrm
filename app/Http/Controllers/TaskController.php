<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    /**
     * Lista zadań
     */
    public function index(Request $request): Response
    {
        $query = Task::with(['status', 'client', 'assignee', 'creator']);

        // Filtrowanie
        if ($search = $request->get('search')) {
            $query->search($search);
        }

        if ($statusId = $request->get('status_id')) {
            $query->where('status_id', $statusId);
        }

        if ($clientId = $request->get('client_id')) {
            $query->where('client_id', $clientId);
        }

        if ($assignedTo = $request->get('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }

        if ($priority = $request->get('priority')) {
            $query->where('priority', $priority);
        }

        // Filtr: tylko moje zadania
        if ($request->get('my_tasks') && auth()->user()) {
            $query->assignedTo(auth()->id());
        }

        // Filtr: przeterminowane
        if ($request->get('overdue')) {
            $query->overdue();
        }

        // Filtr: na dziś
        if ($request->get('today')) {
            $query->today();
        }

        // Kosz
        if ($request->get('trashed')) {
            $query->onlyTrashed();
        }

        // Sortowanie
        $sortBy = $request->get('sort', 'due_date');
        $sortDir = $request->get('dir', 'asc');
        
        if ($sortBy === 'due_date') {
            $query->orderByRaw('due_date IS NULL, due_date ' . $sortDir);
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        $tasks = $query->paginate(15)->withQueryString();

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks,
            'filters' => [
                'search' => $request->get('search', ''),
                'status_id' => $request->get('status_id', ''),
                'client_id' => $request->get('client_id', ''),
                'assigned_to' => $request->get('assigned_to', ''),
                'priority' => $request->get('priority', ''),
                'my_tasks' => $request->boolean('my_tasks'),
                'overdue' => $request->boolean('overdue'),
                'today' => $request->boolean('today'),
                'trashed' => $request->boolean('trashed'),
                'sort' => $sortBy,
                'dir' => $sortDir,
            ],
            'statuses' => Status::ordered()->get(),
            'clients' => Client::active()->orderBy('name')->get(['id', 'name', 'short_name']),
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'priorities' => Task::getPriorities(),
        ]);
    }

    /**
     * Widok Kanban
     */
    public function kanban(Request $request): Response
    {
        $statuses = Status::visible()->ordered()->get();
        
        $tasksByStatus = [];
        foreach ($statuses as $status) {
            $query = Task::with(['client', 'assignee'])
                ->where('status_id', $status->id);
            
            if ($request->get('my_tasks') && auth()->user()) {
                $query->assignedTo(auth()->id());
            }
            
            $tasksByStatus[$status->id] = $query->orderBy('due_date')->get();
        }

        return Inertia::render('Tasks/Kanban', [
            'statuses' => $statuses,
            'tasksByStatus' => $tasksByStatus,
            'filters' => [
                'my_tasks' => $request->boolean('my_tasks'),
            ],
        ]);
    }

    /**
     * Widok Timeline
     */
    public function timeline(Request $request): Response
    {
        $query = Task::with(['status', 'client', 'assignee'])
            ->whereNotNull('due_date');

        if ($request->get('my_tasks') && auth()->user()) {
            $query->assignedTo(auth()->id());
        }

        if ($assignedTo = $request->get('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }

        // Zakres dat (domyślnie: 2 tygodnie wstecz + 4 do przodu)
        $startDate = $request->get('start', now()->subWeeks(2)->toDateString());
        $endDate = $request->get('end', now()->addWeeks(4)->toDateString());

        $query->whereBetween('due_date', [$startDate, $endDate]);

        $tasks = $query->orderBy('due_date')->get();

        return Inertia::render('Tasks/Timeline', [
            'tasks' => $tasks,
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'statuses' => Status::ordered()->get(),
            'filters' => [
                'my_tasks' => $request->boolean('my_tasks'),
                'assigned_to' => $request->get('assigned_to', ''),
                'start' => $startDate,
                'end' => $endDate,
            ],
        ]);
    }

    /**
     * Formularz tworzenia
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Tasks/Form', [
            'task' => null,
            'statuses' => Status::ordered()->get(),
            'clients' => Client::active()->orderBy('name')->get(['id', 'name', 'short_name']),
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'priorities' => Task::getPriorities(),
            'preselectedClientId' => $request->get('client_id'),
        ]);
    }

    /**
     * Zapisz nowe zadanie
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status_id' => 'required|exists:statuses,id',
            'client_id' => 'nullable|exists:clients,id',
            'assigned_to' => 'nullable|exists:users,id',
            'submit_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'priority' => 'required|in:low,medium,high,urgent',
            'estimated_hours' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        $validated['created_by'] = auth()->id();
        $validated['submit_date'] = $validated['submit_date'] ?? now()->toDateString();

        $task = Task::create($validated);

        ActivityLog::log('create', $task, "Utworzono zadanie: {$task->title}");

        return redirect()->route('tasks.index')
            ->with('success', 'Zadanie zostało dodane.');
    }

    /**
     * Pokaż szczegóły zadania
     */
    public function show(Task $task): Response
    {
        $task->load(['status', 'client', 'assignee', 'creator', 'comments.user']);

        $activityLogs = null;

        // Historia zmian — tylko dla adminów i managerów
        if (in_array(auth()->user()->role, ['admin', 'manager'])) {
            $activityLogs = ActivityLog::with('user')
                ->forModel(Task::class, $task->id)
                ->latest()
                ->limit(50)
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'action' => $log->action,
                        'action_label' => $log->action_label,
                        'action_color' => $log->action_color,
                        'description' => $log->description,
                        'old_values' => $log->old_values,
                        'new_values' => $log->new_values,
                        'user_name' => $log->user?->name ?? 'System',
                        'ip_address' => $log->ip_address,
                        'created_at' => $log->created_at->toISOString(),
                    ];
                });
        }

        return Inertia::render('Tasks/Show', [
            'task' => $task,
            'activityLogs' => $activityLogs,
        ]);
    }

    /**
     * Dodaj komentarz do zadania
     */
    public function storeComment(Request $request, Task $task): RedirectResponse
    {
        $validated = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $task->comments()->create([
            'user_id' => auth()->id(),
            'body' => $validated['body'],
        ]);

        return back()->with('success', 'Komentarz dodany.');
    }

    /**
     * Usuń komentarz
     */
    public function destroyComment(Task $task, \App\Models\TaskComment $comment): RedirectResponse
    {
        // Tylko autor lub admin może usunąć
        if ($comment->user_id !== auth()->id() && auth()->user()->role !== 'admin') {
            abort(403);
        }

        $comment->delete();

        return back()->with('success', 'Komentarz usunięty.');
    }

    /**
     * Formularz edycji
     */
    public function edit(Task $task): Response
    {
        return Inertia::render('Tasks/Form', [
            'task' => $task,
            'statuses' => Status::ordered()->get(),
            'clients' => Client::active()->orderBy('name')->get(['id', 'name', 'short_name']),
            'users' => User::active()->orderBy('name')->get(['id', 'name']),
            'priorities' => Task::getPriorities(),
        ]);
    }

    /**
     * Zaktualizuj zadanie
     */
    public function update(Request $request, Task $task): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status_id' => 'required|exists:statuses,id',
            'client_id' => 'nullable|exists:clients,id',
            'assigned_to' => 'nullable|exists:users,id',
            'submit_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'priority' => 'required|in:low,medium,high,urgent',
            'estimated_hours' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        // Sprawdź czy status się zmienił na końcowy
        $newStatus = Status::find($validated['status_id']);
        if ($newStatus && $newStatus->is_final && !$task->completed_at) {
            $validated['completed_at'] = now();
        } elseif ($newStatus && !$newStatus->is_final) {
            $validated['completed_at'] = null;
        }

        $oldValues = $task->toArray();
        $task->update($validated);

        ActivityLog::log('update', $task, "Zaktualizowano zadanie: {$task->title}", $oldValues, $validated);

        return redirect()->route('tasks.index')
            ->with('success', 'Zadanie zostało zaktualizowane.');
    }

    /**
     * Szybka zmiana statusu (AJAX)
     */
    public function updateStatus(Request $request, Task $task): RedirectResponse
    {
        $validated = $request->validate([
            'status_id' => 'required|exists:statuses,id',
        ]);

        $newStatus = Status::find($validated['status_id']);
        
        $updateData = ['status_id' => $validated['status_id']];
        
        if ($newStatus->is_final && !$task->completed_at) {
            $updateData['completed_at'] = now();
        } elseif (!$newStatus->is_final) {
            $updateData['completed_at'] = null;
        }

        $oldStatus = $task->status;
        $oldValues = ['status_id' => $task->status_id, 'completed_at' => $task->completed_at];

        $task->update($updateData);

        ActivityLog::log(
            'update',
            $task,
            'Zmieniono status zadania z ' . ($oldStatus->name ?? '?') . ' na ' . $newStatus->name,
            $oldValues,
            $updateData,
        );

        return back()->with('success', 'Status został zmieniony.');
    }

    /**
     * Usuń zadanie (soft delete - do kosza)
     */
    public function destroy(Task $task): RedirectResponse
    {
        $title = $task->title;
        
        ActivityLog::log('delete', $task, "Usunięto zadanie: {$title}");
        
        $task->delete();

        return redirect()->route('tasks.index')
            ->with('success', 'Zadanie zostało przeniesione do kosza.');
    }

    /**
     * Przywróć zadanie z kosza
     */
    public function restore(int $id): RedirectResponse
    {
        $task = Task::onlyTrashed()->findOrFail($id);
        $task->restore();

        ActivityLog::log('restore', $task, "Przywrócono zadanie: {$task->title}");

        return redirect()->route('tasks.index')
            ->with('success', 'Zadanie zostało przywrócone.');
    }

    /**
     * Bulk actions — masowe operacje na zadaniach
     */
    public function bulkAction(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:tasks,id',
            'action' => 'required|in:delete,change_status,change_priority,assign',
            'status_id' => 'required_if:action,change_status|nullable|exists:statuses,id',
            'priority' => 'required_if:action,change_priority|nullable|in:low,medium,high,urgent',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $tasks = Task::whereIn('id', $validated['ids'])->get();
        $count = $tasks->count();

        switch ($validated['action']) {
            case 'delete':
                foreach ($tasks as $task) {
                    ActivityLog::log('delete', $task, "Bulk: usunięto zadanie: {$task->title}");
                    $task->delete();
                }
                return back()->with('success', "Przeniesiono {$count} zadań do kosza.");

            case 'change_status':
                $status = Status::find($validated['status_id']);
                foreach ($tasks as $task) {
                    $updateData = ['status_id' => $status->id];
                    if ($status->is_final && !$task->completed_at) {
                        $updateData['completed_at'] = now();
                    } elseif (!$status->is_final) {
                        $updateData['completed_at'] = null;
                    }
                    $task->update($updateData);
                }
                ActivityLog::log('update', null, "Bulk: zmieniono status {$count} zadań na: {$status->name}");
                return back()->with('success', 'Zmieniono status ' . $count . ' zadań na: ' . $status->name . '.');

            case 'change_priority':
                Task::whereIn('id', $validated['ids'])->update(['priority' => $validated['priority']]);
                $priorityLabels = ['low' => 'Niski', 'medium' => 'Średni', 'high' => 'Wysoki', 'urgent' => 'Pilny'];
                $label = $priorityLabels[$validated['priority']] ?? $validated['priority'];
                ActivityLog::log('update', null, "Bulk: zmieniono priorytet {$count} zadań na: {$label}");
                return back()->with('success', 'Zmieniono priorytet ' . $count . ' zadań na: ' . $label . '.');

            case 'assign':
                Task::whereIn('id', $validated['ids'])->update(['assigned_to' => $validated['assigned_to']]);
                $userName = $validated['assigned_to'] ? User::find($validated['assigned_to'])->name : 'Brak';
                ActivityLog::log('update', null, "Bulk: przypisano {$count} zadań do: {$userName}");
                return back()->with('success', 'Przypisano ' . $count . ' zadań do: ' . $userName . '.');
        }

        return back();
    }

    /**
     * Trwałe usunięcie zadania
     */
    public function forceDelete(int $id): RedirectResponse
    {
        $task = Task::onlyTrashed()->findOrFail($id);
        $title = $task->title;
        
        $task->forceDelete();

        ActivityLog::log('delete', null, "Trwale usunięto zadanie: {$title}");

        return redirect()->route('tasks.index', ['trashed' => true])
            ->with('success', 'Zadanie zostało trwale usunięte.');
    }
}
