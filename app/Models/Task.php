<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'status_id',
        'client_id',
        'assigned_to',
        'created_by',
        'submit_date',
        'due_date',
        'completed_at',
        'priority',
        'estimated_hours',
        'notes',
        'due_time',
        'recurrence_type',
        'recurrence_interval',
        'recurrence_weekdays',
        'recurrence_until',
        'recurrence_parent_id',
        'reminder_offset_minutes',
        'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'submit_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'estimated_hours' => 'integer',
            'recurrence_weekdays' => 'array',
            'recurrence_until' => 'date',
            'reminder_sent_at' => 'datetime',
        ];
    }

    // ==================== RELACJE ====================

    /**
     * Status zadania
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /**
     * Klient powiązany z zadaniem
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Załączniki zadania.
     */
    public function files(): HasMany
    {
        return $this->hasMany(TaskFile::class)->latest();
    }

    /**
     * Współpracownicy — osoby dopuszczone do zadania poza wykonawcą i autorem.
     */
    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_collaborators')->withTimestamps();
    }

    // ==================== DOSTĘP ====================

    /**
     * Czy użytkownik ma prawo widzieć i edytować to zadanie.
     *
     * Reguła: administrator widzi wszystko, poza tym dostęp mają wyłącznie osoby
     * powiązane z zadaniem — autor, wykonawca i współpracownicy. Bez tego każdy
     * zalogowany widział zadania całej firmy, łącznie z zarządem.
     */
    public function isAccessibleBy(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->hasAdminRights()) {
            return true;
        }

        if ($this->created_by === $user->id || $this->assigned_to === $user->id) {
            return true;
        }

        return $this->collaborators()->where('users.id', $user->id)->exists();
    }

    /**
     * Ta sama reguła co isAccessibleBy(), ale wyrażona w SQL — do zawężania list.
     *
     * Świadomie NIE zawiera wyjątku dla administratora: kto go potrzebuje, ten
     * po prostu nie nakłada tego scope'a. Wbudowanie wyjątku tutaj sprawiłoby,
     * że scope „widoczne dla użytkownika X" znaczyłby co innego zależnie od roli.
     */
    public function scopeVisibleTo($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('created_by', $userId)
                ->orWhere('assigned_to', $userId)
                ->orWhereHas('collaborators', fn ($c) => $c->where('users.id', $userId));
        });
    }

    /**
     * Pełny termin zadania — data plus godzina, jeśli podana.
     *
     * Przypomnienia liczą się od tego momentu. Bez godziny zadanie traktujemy
     * jako całodniowe i przyjmujemy koniec dnia, żeby „przypomnij dzień wcześniej"
     * nie wypadało o północy poprzedniej doby.
     */
    public function getDueAtAttribute(): ?Carbon
    {
        if (!$this->due_date) {
            return null;
        }

        $data = Carbon::parse($this->due_date);

        return $this->due_time
            ? $data->setTimeFromTimeString((string) $this->due_time)
            : $data->endOfDay();
    }

    /**
     * Zadanie należące do serii cyklicznej.
     */
    public function recurrenceParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurrence_parent_id');
    }

    /**
     * Użytkownik przypisany do zadania
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Użytkownik który utworzył zadanie
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Komentarze do zadania
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at', 'desc');
    }

    // ==================== SCOPES ====================

    /**
     * Zadania na dziś
     */
    public function scopeToday($query)
    {
        return $query->whereDate('due_date', Carbon::today());
    }

    /**
     * Zadania przeterminowane
     */
    public function scopeOverdue($query)
    {
        return $query->whereDate('due_date', '<', Carbon::today())
            ->whereHas('status', fn($q) => $q->where('is_final', false));
    }

    /**
     * Zadania nadchodzące (w tym tygodniu)
     */
    public function scopeUpcoming($query)
    {
        return $query->whereBetween('due_date', [
            Carbon::today(),
            Carbon::today()->addWeek()
        ]);
    }

    /**
     * Zadania nieukończone
     */
    public function scopeIncomplete($query)
    {
        return $query->whereHas('status', fn($q) => $q->where('is_final', false));
    }

    /**
     * Zadania ukończone
     */
    public function scopeCompleted($query)
    {
        return $query->whereHas('status', fn($q) => $q->where('is_final', true));
    }

    /**
     * Zadania o danym priorytecie
     */
    public function scopeOfPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Zadania przypisane do użytkownika
     */
    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    /**
     * Zadania dla klienta
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Wyszukiwanie
     */
    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
              ->orWhere('description', 'like', "%{$term}%");
        });
    }

    /**
     * Tylko w koszu
     */
    public function scopeTrashed($query)
    {
        return $query->onlyTrashed();
    }

    // ==================== AKCESORY ====================

    /**
     * Skrócony opis (do 100 znaków)
     */
    public function getShortDescriptionAttribute(): string
    {
        if (!$this->description) {
            return '';
        }
        
        return mb_strlen($this->description) > 100
            ? mb_substr($this->description, 0, 100) . '...'
            : $this->description;
    }

    /**
     * Etykieta priorytetu
     */
    public function getPriorityLabelAttribute(): string
    {
        return match($this->priority) {
            'low' => 'Niski',
            'medium' => 'Średni',
            'high' => 'Wysoki',
            'urgent' => 'Pilny',
            default => $this->priority,
        };
    }

    /**
     * Kolor priorytetu
     */
    public function getPriorityColorAttribute(): string
    {
        return match($this->priority) {
            'low' => '#6B7280',
            'medium' => '#3B82F6',
            'high' => '#F59E0B',
            'urgent' => '#EF4444',
            default => '#6B7280',
        };
    }

    /**
     * Klasa CSS priorytetu
     */
    public function getPriorityClassAttribute(): string
    {
        return match($this->priority) {
            'low' => 'bg-gray-100 text-gray-800',
            'medium' => 'bg-blue-100 text-blue-800',
            'high' => 'bg-yellow-100 text-yellow-800',
            'urgent' => 'bg-red-100 text-red-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    /**
     * Czy zadanie jest przeterminowane
     */
    public function getIsOverdueAttribute(): bool
    {
        if (!$this->due_date) {
            return false;
        }

        return $this->due_date->isPast() && !$this->status?->is_final;
    }

    /**
     * Czy zadanie jest na dziś
     */
    public function getIsTodayAttribute(): bool
    {
        return $this->due_date?->isToday() ?? false;
    }

    /**
     * Czy zadanie jest ukończone
     */
    public function getIsCompletedAttribute(): bool
    {
        return $this->status?->is_final ?? false;
    }

    /**
     * Dni do terminu (ujemne = przeterminowane)
     */
    public function getDaysUntilDueAttribute(): ?int
    {
        if (!$this->due_date) {
            return null;
        }

        return Carbon::today()->diffInDays($this->due_date, false);
    }

    /**
     * Formatowany termin
     */
    public function getFormattedDueDateAttribute(): string
    {
        if (!$this->due_date) {
            return 'Brak terminu';
        }

        if ($this->due_date->isToday()) {
            return 'Dziś';
        }

        if ($this->due_date->isTomorrow()) {
            return 'Jutro';
        }

        if ($this->due_date->isYesterday()) {
            return 'Wczoraj';
        }

        return $this->due_date->format('d.m.Y');
    }

    /**
     * Lista priorytetów
     */
    public static function getPriorities(): array
    {
        return [
            'low' => 'Niski',
            'medium' => 'Średni',
            'high' => 'Wysoki',
            'urgent' => 'Pilny',
        ];
    }

    /**
     * Oznacz jako ukończone
     */
    public function markAsCompleted(): void
    {
        $doneStatus = Status::where('type', 'done')->first();
        
        if ($doneStatus) {
            $this->update([
                'status_id' => $doneStatus->id,
                'completed_at' => now(),
            ]);
        }
    }
}
