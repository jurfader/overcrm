<?php

namespace App\Support\Dashboard;

use App\Models\User;

/**
 * Singleton rejestr widgetów dashboardu.
 *
 * Core widgety rejestruje DashboardServiceProvider w boot().
 * Moduły rejestrują własne przez WidgetRegistry::register() w swoich ServiceProviderach.
 *
 * Przykład rejestracji modułowego widgetu (z modules/Fakturownia/FakturowniaServiceProvider.php):
 *
 *   WidgetRegistry::register(new Widget(
 *       key: 'fakturownia.revenue',
 *       title: 'Przychody',
 *       icon: 'chart-bar',
 *       component: 'FakturowniaRevenue',
 *       defaultWidth: 8,
 *       roles: ['admin', 'manager'],
 *       handler: fn ($user) => app(FakturowniaService::class)->getRevenueStats('month', $user->fakturownia_department_id),
 *       module: 'fakturownia',
 *   ));
 */
class WidgetRegistry
{
    /** @var array<string, Widget> */
    protected array $widgets = [];

    public function register(Widget $widget): void
    {
        $this->widgets[$widget->key] = $widget;
    }

    public function get(string $key): ?Widget
    {
        return $this->widgets[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->widgets[$key]);
    }

    /** @return array<string, Widget> */
    public function all(): array
    {
        return $this->widgets;
    }

    /**
     * Widgety widoczne dla danego usera (filtr po rolach).
     * @return array<string, Widget>
     */
    public function forUser(?User $user): array
    {
        return array_filter($this->widgets, fn(Widget $w) => $w->isVisibleFor($user));
    }

    /**
     * Domyślny layout — wszystkie widgety usera w domyślnej szerokości.
     * Używane gdy user nie ma jeszcze własnego layoutu.
     *
     * @return array<int, array{key: string, width: int, visible: bool}>
     */
    public function defaultLayout(?User $user): array
    {
        return array_values(array_map(
            fn(Widget $w) => ['key' => $w->key, 'width' => $w->defaultWidth, 'visible' => true],
            $this->forUser($user)
        ));
    }

    /**
     * Sanityzacja layoutu z DB:
     * - usuwa nieistniejące widgety (np. po wyłączeniu modułu)
     * - dokleja nowe widgety na koniec (gdy moduł zarejestrował nowe)
     * - clamp width do [1,12]
     *
     * @param  array<int, array> $layout
     * @return array<int, array{key: string, width: int, visible: bool}>
     */
    public function sanitize(?array $layout, ?User $user): array
    {
        $available = $this->forUser($user);
        $clean = [];
        $seen = [];

        foreach ($layout ?? [] as $row) {
            $key = $row['key'] ?? null;
            if (!$key || !isset($available[$key])) continue;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $clean[] = [
                'key'     => $key,
                'width'   => max(1, min(12, (int)($row['width'] ?? $available[$key]->defaultWidth))),
                'visible' => (bool)($row['visible'] ?? true),
            ];
        }

        // Dorzuć nowe widgety które user jeszcze nie widział
        foreach ($available as $key => $widget) {
            if (!isset($seen[$key])) {
                $clean[] = ['key' => $key, 'width' => $widget->defaultWidth, 'visible' => true];
            }
        }

        return $clean;
    }
}
