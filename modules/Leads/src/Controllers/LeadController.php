<?php

namespace Modules\Leads\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Leads\Models\Lead;
use Modules\Leads\Models\LeadStatus;
use Modules\Leads\Services\LeadService;

class LeadController extends Controller
{
    public function __construct(private LeadService $leadService) {}

    public function index(Request $request)
    {
        $statuses = LeadStatus::ordered()->get();

        $query = Lead::with(['status', 'assignee'])
            ->notConverted();

        if ($request->filled('search')) {
            $query->search($request->search);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        $leads = $query->orderBy('created_at', 'desc')->get();

        $leadsByStatus = [];
        foreach ($statuses as $status) {
            $leadsByStatus[$status->id] = $leads->where('status_id', $status->id)->values();
        }

        return Inertia::render('Leads/Index', [
            'statuses' => $statuses,
            'leadsByStatus' => $leadsByStatus,
            'users' => User::where('status', 'active')->get(['id', 'name', 'avatar']),
            'filters' => $request->only(['search', 'source', 'assigned_to']),
            'stats' => $this->leadService->getStatistics(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'nip' => 'nullable|string|max:20',
            'website' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:30',
            'assigned_to' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        $this->leadService->createLead($validated, auth()->id());

        return back()->with('success', 'Lead dodany.');
    }

    public function show(Lead $lead)
    {
        $lead->load(['status', 'assignee', 'activities.user', 'convertedClient']);

        return response()->json($lead);
    }

    public function update(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'nip' => 'nullable|string|max:20',
            'website' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:30',
            'assigned_to' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        $lead->update($validated);

        return response()->json(['success' => true, 'lead' => $lead->fresh('status', 'assignee')]);
    }

    public function destroy(Lead $lead)
    {
        $lead->delete();

        return back()->with('success', 'Lead usunięty.');
    }

    public function updateStatus(Request $request, Lead $lead)
    {
        $request->validate(['status_id' => 'required|exists:lead_statuses,id']);

        $updated = $this->leadService->changeStatus($lead, $request->status_id, auth()->id());

        return response()->json(['success' => true, 'lead' => $updated]);
    }

    public function addNote(Request $request, Lead $lead)
    {
        $request->validate(['note' => 'required|string']);

        $activity = $this->leadService->addNote($lead, $request->note, auth()->id());

        return response()->json(['success' => true, 'activity' => $activity->load('user')]);
    }

    public function convert(Lead $lead)
    {
        if ($lead->is_converted) {
            return response()->json(['error' => 'Lead już został skonwertowany.'], 422);
        }

        $client = $this->leadService->convertToClient($lead, auth()->id());

        return response()->json([
            'success' => true,
            'client_id' => $client->id,
            'message' => "Lead skonwertowany do klienta: {$client->name}",
        ]);
    }

    public function stats()
    {
        return response()->json($this->leadService->getStatistics());
    }
}
