<?php

namespace App\Support\Dashboard;

use App\Models\User;

class Widget
{
    /**
     * @param  string  $key                Unikalny identyfikator (np. "core.tasks-today", "fakturownia.revenue")
     * @param  string  $title              Tytuł wyświetlany na karcie
     * @param  string  $icon               Nazwa ikony z Components/Icons.vue
     * @param  string  $component          Nazwa Vue komponentu (musi być zarejestrowana w Components/Dashboard/widgets.js)
     * @param  int     $defaultWidth       Domyślna szerokość 1-12 (CSS grid columns)
     * @param  int     $minWidth           Minimalna szerokość (do walidacji resize w UI)
     * @param  array   $roles              Role które mogą widzieć ten widget. Pusta = wszyscy.
     * @param  \Closure $handler           fn(User $user) => array — pobiera dane do widgeta
     * @param  string|null $description    Opis pokazywany w pickerze "Dodaj widget"
     * @param  string|null $module         Nazwa modułu który zarejestrował widget (null = core)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $icon,
        public readonly string $component,
        public readonly int $defaultWidth = 4,
        public readonly int $minWidth = 3,
        public readonly array $roles = [],
        public readonly ?\Closure $handler = null,
        public readonly ?string $description = null,
        public readonly ?string $module = null,
    ) {}

    public function isVisibleFor(?User $user): bool
    {
        if (empty($this->roles)) return true;
        if (!$user) return false;
        return in_array($user->role, $this->roles, true);
    }

    public function fetch(?User $user): mixed
    {
        if (!$this->handler) return null;
        return ($this->handler)($user);
    }

    public function toMeta(): array
    {
        return [
            'key'           => $this->key,
            'title'         => $this->title,
            'icon'          => $this->icon,
            'component'     => $this->component,
            'default_width' => $this->defaultWidth,
            'min_width'     => $this->minWidth,
            'description'   => $this->description,
            'module'        => $this->module,
        ];
    }
}
