<?php

namespace App\Http\Controllers;

use App\Support\Dashboard\WidgetRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(protected WidgetRegistry $registry) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $available = $this->registry->forUser($user);

        // Layout = persistowane preferencje + sanityzacja (dorzuca nowe, usuwa stare)
        $layout = $this->registry->sanitize($user?->dashboard_layout, $user);

        // Pobierz dane dla widoczynych widgetów (handler invocation)
        $widgetData = [];
        foreach ($layout as $row) {
            if (!$row['visible']) continue;
            $widget = $available[$row['key']] ?? null;
            if (!$widget) continue;
            try {
                $widgetData[$row['key']] = $widget->fetch($user);
            } catch (\Throwable $e) {
                $widgetData[$row['key']] = ['error' => 'Nie udało się pobrać danych'];
            }
        }

        // Meta wszystkich dostępnych widgetów (do pickera "Dodaj widget")
        $widgetMeta = array_values(array_map(fn($w) => $w->toMeta(), $available));

        return Inertia::render('Dashboard', [
            'layout'     => $layout,
            'widgetMeta' => $widgetMeta,
            'widgetData' => $widgetData,
        ]);
    }

    public function saveLayout(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'layout'             => 'required|array',
            'layout.*.key'       => 'required|string|max:80',
            'layout.*.width'     => 'required|integer|min:1|max:12',
            'layout.*.visible'   => 'required|boolean',
        ]);

        $user = $request->user();
        $clean = $this->registry->sanitize($data['layout'], $user);

        $user->update(['dashboard_layout' => $clean]);

        return back();
    }

    public function resetLayout(Request $request): RedirectResponse
    {
        $request->user()->update(['dashboard_layout' => null]);
        return back();
    }
}
