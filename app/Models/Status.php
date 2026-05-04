<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Status extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'color',
        'bg_class',
        'order',
        'is_default',
        'is_visible',
        'is_final',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_visible' => 'boolean',
            'is_final' => 'boolean',
            'order' => 'integer',
        ];
    }

    // ==================== RELACJE ====================

    /**
     * Zadania z tym statusem
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    // ==================== SCOPES ====================

    /**
     * Tylko widoczne statusy
     */
    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    /**
     * Sortuj według kolejności
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    /**
     * Statusy określonego typu
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // ==================== AKCESORY ====================

    /**
     * Etykieta typu
     */
    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'new' => 'Nowy',
            'in_progress' => 'W trakcie',
            'done' => 'Wykonane',
            'cancelled' => 'Anulowane',
            default => $this->type,
        };
    }

    /**
     * Klasa CSS dla tła
     */
    public function getBgClassAttribute(): string
    {
        if ($this->attributes['bg_class']) {
            return $this->attributes['bg_class'];
        }

        // Generuj klasę na podstawie koloru
        return match($this->type) {
            'new' => 'bg-blue-100 text-blue-800',
            'in_progress' => 'bg-yellow-100 text-yellow-800',
            'done' => 'bg-green-100 text-green-800',
            'cancelled' => 'bg-gray-100 text-gray-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    /**
     * Liczba zadań z tym statusem
     */
    public function getTasksCountAttribute(): int
    {
        return $this->tasks()->count();
    }

    /**
     * Pobierz domyślny status
     */
    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first()
            ?? static::ordered()->first();
    }

    /**
     * Lista typów statusów
     */
    public static function getTypes(): array
    {
        return [
            'new' => 'Nowy',
            'in_progress' => 'W trakcie',
            'done' => 'Wykonane',
            'cancelled' => 'Anulowane',
        ];
    }

    /**
     * Predefiniowane kolory
     */
    public static function getColors(): array
    {
        return [
            '#3B82F6' => 'Niebieski',
            '#10B981' => 'Zielony',
            '#F59E0B' => 'Pomarańczowy',
            '#EF4444' => 'Czerwony',
            '#8B5CF6' => 'Fioletowy',
            '#EC4899' => 'Różowy',
            '#6B7280' => 'Szary',
            '#14B8A6' => 'Turkusowy',
            '#F97316' => 'Ciemny pomarańcz',
            '#84CC16' => 'Limonkowy',
        ];
    }
}
