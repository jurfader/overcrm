<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationLog extends Model
{
    protected $fillable = [
        'service',
        'method',
        'endpoint',
        'request_data',
        'response_status',
        'response_summary',
        'duration_ms',
        'status',
        'error_message',
        'user_id',
    ];

    protected $casts = [
        'request_data' => 'array',
    ];

    // ==================== RELACJE ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== SCOPES ====================

    public function scopeForService($query, string $service)
    {
        return $query->where('service', $service);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'error');
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ==================== HELPERY ====================

    /**
     * Zaloguj wywołanie API
     */
    public static function logCall(
        string $service,
        string $method,
        string $endpoint,
        ?array $requestData,
        ?int $responseStatus,
        ?string $responseSummary,
        ?int $durationMs,
        string $status = 'success',
        ?string $errorMessage = null,
    ): self {
        return self::create([
            'service' => $service,
            'method' => $method,
            'endpoint' => $endpoint,
            'request_data' => $requestData,
            'response_status' => $responseStatus,
            'response_summary' => $responseSummary ? mb_substr($responseSummary, 0, 500) : null,
            'duration_ms' => $durationMs,
            'status' => $status,
            'error_message' => $errorMessage,
            'user_id' => auth()->id(),
        ]);
    }

    /**
     * Etykieta serwisu
     */
    public function getServiceLabelAttribute(): string
    {
        return match ($this->service) {
            'fakturownia' => 'Fakturownia',
            'apilo' => 'Apilo',
            'gus' => 'GUS',
            'ringostat' => 'Ringostat',
            default => ucfirst($this->service),
        };
    }
}
