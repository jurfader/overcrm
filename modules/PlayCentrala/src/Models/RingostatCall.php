<?php

namespace Modules\PlayCentrala\Models;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RingostatCall extends Model
{
    protected $table = 'ringostat_calls';

    protected $fillable = [
        'call_id', 'caller', 'destination', 'answered_by_number',
        'call_type', 'disposition',
        'call_date', 'duration', 'wait_time', 'billsec',
        'recording_url', 'recording_wav_url', 'encryption_key_name',
        'employee_id', 'employee_name', 'department',
        'utm_source', 'utm_medium', 'utm_campaign',
        'landing_page', 'referrer', 'call_card_url',
        'scheme_name', 'missing_reason',
        'client_id', 'visit_id', 'user_id',
        'ai_transcript', 'ai_summary',
        'ai_customer_mood', 'ai_employee_mood', 'ai_overall_mood',
        'ai_recommendations', 'ai_analysis', 'ai_transcript_url',
        'ai_profile_suggestions',
    ];

    protected $casts = [
        'call_date'             => 'datetime',
        'duration'              => 'integer',
        'wait_time'             => 'integer',
        'billsec'               => 'integer',
        'ai_analysis'           => 'array',
        'ai_profile_suggestions' => 'array',
    ];

    // ==================== RELACJE ====================

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ClientVisit::class, 'visit_id');
    }

    // ==================== SCOPES ====================

    public function scopeIncoming($query)
    {
        return $query->where('call_type', 'in');
    }

    public function scopeOutgoing($query)
    {
        return $query->where('call_type', 'out');
    }

    public function scopeAnswered($query)
    {
        return $query->whereIn('disposition', ['ANSWERED', 'CONNECTED']);
    }

    public function scopeMissed($query)
    {
        return $query->whereIn('disposition', ['NO ANSWER', 'MISSED']);
    }

    public function scopeForDate($query, string $date)
    {
        return $query->whereDate('call_date', $date);
    }

    public function scopeForDateRange($query, string $from, string $to)
    {
        return $query->whereBetween('call_date', [$from, $to]);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeWithRecording($query)
    {
        return $query->whereNotNull('recording_url');
    }

    // ==================== ACCESSORS ====================

    public function getFormattedDurationAttribute(): string
    {
        $seconds = $this->billsec ?: $this->duration;
        $minutes = floor($seconds / 60);
        $secs    = $seconds % 60;
        return sprintf('%d:%02d', $minutes, $secs);
    }

    public function getCallTypeLabelAttribute(): string
    {
        return match ($this->call_type) {
            'in'  => 'Przychodzące',
            'out' => 'Wychodzące',
            default => ucfirst($this->call_type ?? ''),
        };
    }

    public function getDispositionLabelAttribute(): string
    {
        return match (strtoupper($this->disposition ?? '')) {
            'ANSWERED'    => 'Odebrane',
            'CONNECTED'   => 'Odebrane (przekierowanie)',
            'ESTABLISHED' => 'Nawiązane',
            'REDIRECTED'  => 'Przekierowane',
            'NO ANSWER'   => 'Nieodebrane',
            'MISSED'      => 'Nieodebrane',
            'BUSY'        => 'Zajęte',
            'FAILED'      => 'Nieudane',
            default       => $this->disposition ?? '',
        };
    }

    public function getDispositionColorAttribute(): string
    {
        return match (strtoupper($this->disposition ?? '')) {
            'ANSWERED', 'CONNECTED'   => 'green',
            'NO ANSWER', 'MISSED'     => 'red',
            'ESTABLISHED', 'REDIRECTED' => 'yellow',
            default                   => 'gray',
        };
    }

    public function getHasRecordingAttribute(): bool
    {
        return !empty($this->recording_url);
    }

    public function getHasAiDataAttribute(): bool
    {
        return !empty($this->ai_transcript) || !empty($this->ai_summary);
    }

    public function getHasProfileSuggestionsAttribute(): bool
    {
        return !empty($this->ai_profile_suggestions);
    }

    // ==================== METODY ====================

    /**
     * Znajdź wizytę której telefon pasuje do numeru rozmowy.
     * Najświeższa wizyta (najnowsza visit_date) jeśli kilka pasuje.
     */
    public function matchVisit(): ?\App\Models\ClientVisit
    {
        $phoneToMatch = $this->call_type === 'out' ? $this->destination : $this->caller;
        if (empty($phoneToMatch)) return null;

        $normalized = preg_replace('/\D+/', '', $phoneToMatch);
        if (strlen($normalized) > 9 && str_starts_with($normalized, '48')) {
            $normalized = substr($normalized, 2);
        }
        if (strlen($normalized) < 7) return null;

        // phones_normalized format: " 500123456 500999888 " — spacja z obu stron
        return \App\Models\ClientVisit::whereNotNull('phones_normalized')
            ->where('phones_normalized', 'LIKE', '% ' . $normalized . ' %')
            ->orderByDesc('visit_date')
            ->with('client')
            ->first();
    }

    /**
     * Dopasuj klienta po numerze telefonu. Priorytet:
     *  1. Klient wizyty która ma ten numer (matchVisit)
     *  2. Klient z bazy po polach phone/phone2/contact_phone
     */
    public function matchClient(): ?Client
    {
        // Priorytet: wizyta
        $visit = $this->matchVisit();
        if ($visit) {
            $updates = ['visit_id' => $visit->id];
            if ($visit->client_id) {
                $updates['client_id'] = $visit->client_id;
            }
            $this->update($updates);
            if ($visit->client) return $visit->client;
        }

        $phoneToMatch = $this->call_type === 'out' ? $this->destination : $this->caller;

        if (empty($phoneToMatch)) {
            return null;
        }

        $normalized = preg_replace('/[^0-9]/', '', $phoneToMatch);
        $variants   = [$phoneToMatch, $normalized];

        if (strlen($normalized) >= 9) {
            $variants[] = substr($normalized, -9);
        }
        if (str_starts_with($normalized, '48') && strlen($normalized) > 9) {
            $variants[] = substr($normalized, 2); // bez prefiksu 48
        }

        $client = Client::where(function ($q) use ($variants) {
            foreach (['phone', 'phone2', 'contact_phone'] as $field) {
                foreach ($variants as $variant) {
                    $q->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE({$field}, '+', ''), ' ', ''), '-', ''), '(', '') LIKE ?",
                        ['%' . $variant . '%']
                    );
                }
            }
        })->first();

        if ($client) {
            $this->update(['client_id' => $client->id]);
        }

        return $client;
    }

    /**
     * Dopasuj pracownika CRM po numerze telefonu w Play (play_phone w tabeli users).
     * Dla połączeń wychodzących: callingNumber = employee_id
     * Dla połączeń przychodzących: answeredByNumber = employee_id (kto odebrał)
     */
    public function matchUser(): ?User
    {
        $phoneToMatch = $this->employee_id ? trim($this->employee_id) : null;

        if (empty($phoneToMatch)) {
            return null;
        }

        $normalized = preg_replace('/[^0-9]/', '', $phoneToMatch);
        $variants   = array_unique(array_filter([
            $normalized,
            // Wersja bez prefiksu 48 (9 cyfr)
            str_starts_with($normalized, '48') ? substr($normalized, 2) : null,
        ]));

        $user = User::where(function ($q) use ($variants) {
            foreach ($variants as $variant) {
                $q->orWhereRaw(
                    "REPLACE(REPLACE(play_phone, ' ', ''), '-', '') LIKE ?",
                    ['%' . $variant . '%']
                );
            }
        })->whereNotNull('play_phone')->first();

        if ($user) {
            $this->update([
                'user_id'       => $user->id,
                'employee_name' => $user->name,
            ]);
        }

        return $user;
    }
}
