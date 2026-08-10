<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Załącznik zadania — wskazanie na plik u dostawcy zdolności `storage`.
 *
 * Sam plik żyje po stronie dostawcy (dysk lokalny, Dysk Google…). Tutaj są
 * metadane potrzebne do pokazania listy bez odpytywania go przy każdym renderze.
 */
class TaskFile extends Model
{
    protected $fillable = [
        'task_id',
        'user_id',
        'provider',
        'external_id',
        'name',
        'mime',
        'size',
        'web_url',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Czy plik nadal leży u dostawcy, który jest teraz aktywny.
     *
     * Po przełączeniu dostawcy starsze załączniki zostają przy poprzednim.
     * Interfejs musi to pokazać, zamiast oferować pobranie, które i tak padnie.
     */
    public function isReachable(): bool
    {
        return $this->provider === app(\App\Support\Providers\ProviderRegistry::class)->activeKey('storage');
    }

    /** Rozmiar w formie czytelnej dla człowieka. */
    public function getReadableSizeAttribute(): string
    {
        $bajty = (int) $this->size;

        if ($bajty <= 0) {
            return '—';
        }

        foreach (['B', 'KB', 'MB', 'GB'] as $jednostka) {
            if ($bajty < 1024) {
                return round($bajty, $jednostka === 'B' ? 0 : 1).' '.$jednostka;
            }
            $bajty /= 1024;
        }

        return round($bajty, 1).' TB';
    }
}
