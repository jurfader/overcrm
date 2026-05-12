<?php

namespace Modules\Ringostat\Models;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Polaczenie z Ringostat.net (modul Ringostat — nie myl z PlayCentrala\Models\RingostatCall
 * ktore zyje w `ringostat_calls`).
 */
class Call extends Model
{
    protected $table = 'ringostat_calls_v2';

    protected $fillable = [
        'ringostat_call_id', 'direction',
        'caller', 'callee', 'sip',
        'started_at', 'duration', 'billsec',
        'status', 'recording_url',
        'client_id', 'user_id',
        'webhook_payload',
    ];

    protected $casts = [
        'started_at'      => 'datetime',
        'duration'        => 'integer',
        'billsec'         => 'integer',
        'webhook_payload' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeIncoming($q)
    {
        return $q->where('direction', 'in');
    }

    public function scopeOutgoing($q)
    {
        return $q->where('direction', 'out');
    }

    public function scopeAnswered($q)
    {
        return $q->where('status', 'answered');
    }

    public function scopeMissed($q)
    {
        return $q->whereIn('status', ['missed', 'no answer', 'rejected']);
    }

    /**
     * Pasujacy numer drugiej strony (dla in: caller, dla out: callee).
     */
    public function getOtherPartyAttribute(): ?string
    {
        return $this->direction === 'in' ? $this->caller : $this->callee;
    }

    public function getFormattedDurationAttribute(): string
    {
        $s = (int) ($this->billsec ?: $this->duration);
        return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    /**
     * Normalizuje numer do formatu cyfr (bez prefiksu kraju +48 jezeli 11+ cyfr).
     */
    public static function normalizePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) > 9 && str_starts_with($digits, '48')) {
            return substr($digits, 2);
        }
        return $digits;
    }

    /**
     * Dopasuj Client po caller/callee. Sprawdza phone/phone2/contact_phone
     * po cyfrowej normalizacji. Zapisuje znaleziony client_id.
     */
    public function matchClient(): ?Client
    {
        $normalized = self::normalizePhone($this->other_party);
        if (strlen($normalized) < 7) return null;

        $client = Client::query()
            ->where(function ($q) use ($normalized) {
                foreach (['phone', 'phone2', 'contact_phone'] as $field) {
                    $q->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE({$field}, '+', ''), ' ', ''), '-', ''), '(', '') LIKE ?",
                        ['%' . $normalized . '%']
                    );
                }
            })
            ->first();

        if ($client) {
            $this->update(['client_id' => $client->id]);
        }
        return $client;
    }

    /**
     * Dopasuj User po SIP account (User::sip_account jezeli istnieje).
     */
    public function matchUser(): ?User
    {
        if (empty($this->sip)) return null;

        $user = User::query()
            ->where('sip_account', $this->sip)
            ->first();

        if ($user) {
            $this->update(['user_id' => $user->id]);
        }
        return $user;
    }
}
