<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmailTemplateController extends Controller
{
    public function index()
    {
        $templates = EmailTemplate::with('creator')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/EmailTemplates/Index', [
            'templates' => $templates,
            'categories' => [
                'offer' => 'Oferty',
                'reminder' => 'Przypomnienia',
                'notification' => 'Powiadomienia',
                'other' => 'Inne',
            ],
            'availableVariables' => EmailTemplate::$availableVariables,
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/EmailTemplates/Form', [
            'template' => null,
            'categories' => [
                'offer' => 'Oferty',
                'reminder' => 'Przypomnienia',
                'notification' => 'Powiadomienia',
                'other' => 'Inne',
            ],
            'availableVariables' => EmailTemplate::$availableVariables,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'html_content' => 'required|string',
            'category' => 'required|in:offer,reminder,notification,other',
            'is_active' => 'boolean',
        ]);

        $validated['created_by'] = auth()->id();
        $validated['variables'] = $this->extractVariables($validated['html_content'], $validated['subject']);

        EmailTemplate::create($validated);

        return redirect()->route('admin.email-templates.index')
            ->with('success', 'Szablon został utworzony.');
    }

    public function edit(EmailTemplate $emailTemplate)
    {
        return Inertia::render('Admin/EmailTemplates/Form', [
            'template' => $emailTemplate,
            'categories' => [
                'offer' => 'Oferty',
                'reminder' => 'Przypomnienia',
                'notification' => 'Powiadomienia',
                'other' => 'Inne',
            ],
            'availableVariables' => EmailTemplate::$availableVariables,
        ]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'html_content' => 'required|string',
            'category' => 'required|in:offer,reminder,notification,other',
            'is_active' => 'boolean',
        ]);

        $validated['variables'] = $this->extractVariables($validated['html_content'], $validated['subject']);

        $emailTemplate->update($validated);

        return redirect()->route('admin.email-templates.index')
            ->with('success', 'Szablon został zaktualizowany.');
    }

    public function destroy(EmailTemplate $emailTemplate)
    {
        $emailTemplate->delete();

        return redirect()->route('admin.email-templates.index')
            ->with('success', 'Szablon został usunięty.');
    }

    public function preview(Request $request, EmailTemplate $emailTemplate)
    {
        // Przykładowe dane do podglądu
        $sampleData = [
            'client_name' => 'Przykładowy Klient Sp. z o.o.',
            'client_email' => 'klient@example.com',
            'client_phone' => '+48 123 456 789',
            'client_nip' => '1234567890',
            'client_address' => 'ul. Przykładowa 1, 00-001 Warszawa',
            'visit_date' => now()->addDays(7)->format('d.m.Y'),
            'visit_time' => '10:00',
            'visit_title' => 'Spotkanie handlowe',
            'visit_notes' => 'Omówienie warunków współpracy',
            'user_name' => auth()->user()->name,
            'user_email' => auth()->user()->email,
            'user_phone' => '+48 987 654 321',
            'current_date' => now()->format('d.m.Y'),
            'company_name' => config('app.name', 'CHICKENKING'),
        ];

        return response()->json([
            'subject' => $emailTemplate->renderSubject($sampleData),
            'html' => $emailTemplate->render($sampleData),
        ]);
    }

    public function duplicate(EmailTemplate $emailTemplate)
    {
        $newTemplate = $emailTemplate->replicate();
        $newTemplate->name = $emailTemplate->name . ' (kopia)';
        $newTemplate->slug = null; // Pozwól na automatyczne wygenerowanie
        $newTemplate->created_by = auth()->id();
        $newTemplate->save();

        return redirect()->route('admin.email-templates.edit', $newTemplate)
            ->with('success', 'Szablon został zduplikowany.');
    }

    /**
     * Wyodrębnij użyte zmienne z treści
     */
    private function extractVariables(string $htmlContent, string $subject): array
    {
        $content = $htmlContent . ' ' . $subject;
        preg_match_all('/\{\{(\w+)\}\}/', $content, $matches);
        
        return array_unique($matches[1] ?? []);
    }
}
