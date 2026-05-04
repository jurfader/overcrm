<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'module',
        'description',
    ];

    // ==================== RELACJE ====================

    /**
     * Użytkownicy z tym uprawnieniem
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permissions')
            ->withTimestamps();
    }

    // ==================== SCOPES ====================

    /**
     * Uprawnienia dla modułu
     */
    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    // ==================== STATYCZNE ====================

    /**
     * Pobierz uprawnienie po kodzie
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    /**
     * Lista modułów
     */
    public static function getModules(): array
    {
        return [
            'tasks' => 'Zadania',
            'clients' => 'Klienci',
            'users' => 'Użytkownicy',
            'statuses' => 'Statusy',
            'settings' => 'Ustawienia',
            'reports' => 'Raporty',
        ];
    }

    /**
     * Pogrupowane uprawnienia
     */
    public static function grouped(): array
    {
        $permissions = static::all();
        $grouped = [];

        foreach ($permissions as $permission) {
            $grouped[$permission->module][] = $permission;
        }

        return $grouped;
    }
}
