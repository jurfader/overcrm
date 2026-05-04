<?php

namespace Modules\Leads\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Leads\Models\LeadStatus;

class LeadStatusController extends Controller
{
    public function index()
    {
        return Inertia::render('Leads/Admin/Statuses', [
            'statuses' => LeadStatus::ordered()->withCount('leads')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:lead_statuses,slug',
            'color' => 'required|string|max:7',
            'is_terminal' => 'boolean',
        ]);

        $validated['order'] = LeadStatus::max('order') + 1;

        LeadStatus::create($validated);

        return back()->with('success', 'Status dodany.');
    }

    public function update(Request $request, LeadStatus $status)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'required|string|max:7',
            'is_terminal' => 'boolean',
            'auto_rules' => 'nullable|array',
        ]);

        $status->update($validated);

        return back()->with('success', 'Status zaktualizowany.');
    }

    public function destroy(LeadStatus $status)
    {
        if ($status->leads()->exists()) {
            return back()->with('error', 'Nie można usunąć statusu z przypisanymi leadami.');
        }

        $status->delete();

        return back()->with('success', 'Status usunięty.');
    }

    public function reorder(Request $request)
    {
        $request->validate(['ids' => 'required|array', 'ids.*' => 'exists:lead_statuses,id']);

        foreach ($request->ids as $index => $id) {
            LeadStatus::where('id', $id)->update(['order' => $index + 1]);
        }

        return response()->json(['success' => true]);
    }
}
