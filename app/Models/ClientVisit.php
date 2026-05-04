<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientVisit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'user_id',
        'status_id',
        'visit_date',
        'visit_time',
        'title',
        'description',
        'notes',
        'phones',
        'phones_normalized',
        'link',
        'website',
        'color',
        'status',
        'deadline',
        'order_value',
        'apilo_order_id',
    ];

    protected $casts = [
        'visit_date' => 'date:Y-m-d',
        'visit_time' => 'datetime:H:i',
        'deadline' => 'datetime:Y-m-d\TH:i',
        'order_value' => 'decimal:2',
        'phones' => 'array',
    ];

    /**
     * Normalizuje pojedynczy numer telefonu — same cyfry, bez prefiksu 48 dla porównywania.
     * "+48 500 123 456" → "500123456"
     */
    public static function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone);
        if (strlen($normalized) > 9 && str_starts_with($normalized, '48')) {
            $normalized = substr($normalized, 2);
        }
        return $normalized;
    }

    /**
     * Tworzy string phones_normalized z tablicy oryginalnych numerów.
     * Format: " 500123456 500999888 " — spacja z obu stron każdego numeru dla wydajnego LIKE
     */
    public static function buildNormalizedPhones(?array $phones): ?string
    {
        if (empty($phones)) return null;

        $normalized = array_filter(array_map(
            fn ($p) => self::normalizePhone((string) $p),
            $phones
        ), fn ($p) => strlen($p) >= 7);

        if (empty($normalized)) return null;

        return ' ' . implode(' ', array_unique($normalized)) . ' ';
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function getDisplayTitleAttribute(): string
    {
        if ($this->title) {
            return $this->title;
        }
        
        return $this->client?->name ?? 'Wizyta';
    }

    public function getTimeDisplayAttribute(): string
    {
        if ($this->visit_time) {
            return $this->visit_time->format('H:i');
        }
        return '';
    }

    public function scopeForMonth($query, int $year, int $month)
    {
        return $query->whereYear('visit_date', $year)
                     ->whereMonth('visit_date', $month);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('visit_date', $date);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
