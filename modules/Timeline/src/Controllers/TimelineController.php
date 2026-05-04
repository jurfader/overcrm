<?php

namespace Modules\Timeline\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TimelineController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Task::with(['status', 'client', 'assignee'])
            ->whereNotNull('due_date');

        if ($request->get('my_tasks') && auth()->user()) {
            $query->assignedTo(auth()->id());
        }

        if ($assignedTo = $request->get('assigned_to')) {
            $query->where('assigned_to', $assignedTo);
        }

        $startDate = $request->get('start', now()->subWeeks(2)->toDateString());
        $endDate = $request->get('end', now()->addWeeks(4)->toDateString());

        $query->whereBetween('due_date', [$startDate, $endDate]);

        $tasks = $query->orderBy('due_date')->get();

        return Inertia::render('Timeline/Index', [
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
}
