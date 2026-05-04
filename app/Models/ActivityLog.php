<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $table = 'activity_log';

    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    // ==================== RELACJE ====================

    /**
     * Użytkownik który wykonał akcję
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Powiązany model (polimorficzna relacja)
     */
    public function subject(): MorphTo
    {
        return $this->morphTo('model');
    }

    // ==================== SCOPES ====================

    /**
     * Logi dla określonej akcji
     */
    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Logi dla użytkownika
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Logi dla modelu
     */
    public function scopeForModel($query, string $modelType, ?int $modelId = null)
    {
        $query->where('model_type', $modelType);
        
        if ($modelId) {
            $query->where('model_id', $modelId);
        }
        
        return $query;
    }

    /**
     * Ostatnie logi
     */
    public function scopeRecent($query, int $limit = 50)
    {
        return $query->latest()->limit($limit);
    }

    // ==================== AKCESORY ====================

    /**
     * Etykieta akcji
     */
    public function getActionLabelAttribute(): string
    {
        return match($this->action) {
            'login' => 'Logowanie',
            'logout' => 'Wylogowanie',
            'create' => 'Utworzenie',
            'update' => 'Aktualizacja',
            'delete' => 'Usunięcie',
            'restore' => 'Przywrócenie',
            'password_reset' => 'Reset hasła',
            default => $this->action,
        };
    }

    /**
     * Kolor akcji
     */
    public function getActionColorAttribute(): string
    {
        return match($this->action) {
            'login' => 'blue',
            'logout' => 'gray',
            'create' => 'green',
            'update' => 'yellow',
            'delete' => 'red',
            'restore' => 'purple',
            default => 'gray',
        };
    }

    /**
     * Ikona akcji
     */
    public function getActionIconAttribute(): string
    {
        return match($this->action) {
            'login' => 'login',
            'logout' => 'logout',
            'create' => 'plus',
            'update' => 'pencil',
            'delete' => 'trash',
            'restore' => 'refresh',
            default => 'document',
        };
    }

    /**
     * Nazwa modelu
     */
    public function getModelNameAttribute(): string
    {
        if (!$this->model_type) {
            return '';
        }

        $map = [
            Task::class => 'Zadanie',
            Client::class => 'Klient',
            ClientVisit::class => 'Wizyta',
            User::class => 'Użytkownik',
            Status::class => 'Status',
        ];

        return $map[$this->model_type] ?? class_basename($this->model_type);
    }

    // ==================== STATYCZNE ====================

    /**
     * Zapisz log aktywności
     */
    public static function log(
        string $action,
        ?Model $model = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): self {
        return static::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model?->id,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Lista akcji
     */
    public static function getActions(): array
    {
        return [
            'login' => 'Logowanie',
            'logout' => 'Wylogowanie',
            'create' => 'Utworzenie',
            'update' => 'Aktualizacja',
            'delete' => 'Usunięcie',
            'restore' => 'Przywrócenie',
            'password_reset' => 'Reset hasła',
        ];
    }
}
