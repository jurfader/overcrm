<?php

namespace Modules\Kanban\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Status;
use App\Models\Task;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KanbanController extends Controller
{
    public function index(Request $request): Response
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

        return Inertia::render('Kanban/Index', [
            'statuses' => $statuses,
            'tasksByStatus' => $tasksByStatus,
            'filters' => [
                'my_tasks' => $request->boolean('my_tasks'),
            ],
        ]);
    }
}
