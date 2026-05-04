<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'type',
        'name',
        'short_name',
        'nip',
        'regon',
        'email',
        'phone',
        'phone2',
        'website',
        'street',
        'building_number',
        'apartment_number',
        'postal_code',
        'city',
        'country',
        'contact_person',
        'contact_email',
        'contact_phone',
        'status',
        'client_status',
        'notes',
        'assigned_to',
        'birthday',
        'profile',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'profile' => 'array',
        ];
    }

    // ==================== RELACJE ====================

    /**
     * Użytkownik który utworzył klienta
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Opiekun handlowy (przypisany do klienta)
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Zadania powiązane z klientem
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Wizyty/spotkania powiązane z klientem
     */
    public function clientVisits(): HasMany
    {
        return $this->hasMany(ClientVisit::class);
    }

    /**
     * Podsumowania AI wygenerowane dla klienta
     */
    public function summaries(): HasMany
    {
        return $this->hasMany(ClientSummary::class);
    }

    // ==================== SCOPES ====================

    /**
     * Tylko aktywni klienci
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Tylko firmy
     */
    public function scopeCompanies($query)
    {
        return $query->where('type', 'company');
    }

    /**
     * Tylko osoby prywatne
     */
    public function scopePersons($query)
    {
        return $query->where('type', 'person');
    }

    /**
     * Wyszukiwanie po nazwie, NIP lub email
     */
    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('short_name', 'like', "%{$term}%")
              ->orWhere('nip', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%")
              ->orWhere('phone', 'like', "%{$term}%");
        });
    }

    // ==================== AKCESORY ====================

    /**
     * Pełny adres
     */
    public function getFullAddressAttribute(): string
    {
        $parts = [];
        
        if ($this->street) {
            $address = $this->street;
            if ($this->building_number) {
                $address .= ' ' . $this->building_number;
            }
            if ($this->apartment_number) {
                $address .= '/' . $this->apartment_number;
            }
            $parts[] = $address;
        }
        
        if ($this->postal_code && $this->city) {
            $parts[] = $this->postal_code . ' ' . $this->city;
        } elseif ($this->city) {
            $parts[] = $this->city;
        }
        
        if ($this->country && $this->country !== 'Polska') {
            $parts[] = $this->country;
        }
        
        return implode(', ', $parts);
    }

    /**
     * Nazwa wyświetlana (skrócona lub pełna)
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->short_name ?: $this->name;
    }

    /**
     * Etykieta typu
     */
    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'company' => 'Firma',
            'person' => 'Osoba prywatna',
            default => $this->type,
        };
    }

    /**
     * Etykieta statusu (client_status ma pierwszeństwo nad status)
     */
    public function getStatusLabelAttribute(): string
    {
        if (!empty($this->client_status)) {
            return $this->client_status;
        }
        return match($this->status) {
            'active' => 'Aktywny',
            'inactive' => 'Nieaktywny',
            'potential' => 'Potencjalny',
            default => $this->status,
        };
    }

    /**
     * Kolor statusu dla badge (client_status → domyślnie niebieski)
     */
    public function getStatusColorAttribute(): string
    {
        if (!empty($this->client_status)) {
            $slug = strtolower($this->client_status);
            return match($slug) {
                'stripsiak', 'test' => 'yellow',
                'allegro' => 'blue',
                default => 'blue',
            };
        }
        return match($this->status) {
            'active' => 'green',
            'inactive' => 'gray',
            'potential' => 'yellow',
            default => 'gray',
        };
    }

    /**
     * Czy jest firmą
     */
    public function isCompany(): bool
    {
        return $this->type === 'company';
    }

    /**
     * Liczba zadań
     */
    public function getTasksCountAttribute(): int
    {
        return $this->tasks()->count();
    }

    /**
     * Liczba aktywnych zadań
     */
    public function getActiveTasksCountAttribute(): int
    {
        return $this->tasks()
            ->whereHas('status', fn($q) => $q->where('is_final', false))
            ->count();
    }
}
