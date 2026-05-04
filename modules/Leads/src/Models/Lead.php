<?php

namespace Modules\Leads\Models;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'name', 'company_name', 'email', 'phone', 'nip', 'website',
        'address', 'city', 'source', 'status_id', 'assigned_to',
        'notes', 'metadata', 'converted_to_client_id', 'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'converted_at' => 'datetime',
        ];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'status_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_to_client_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->orderByDesc('created_at');
    }

    public function scopeNotConverted($query)
    {
        return $query->whereNull('converted_to_client_id');
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('company_name', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%")
              ->orWhere('phone', 'like', "%{$term}%")
              ->orWhere('city', 'like', "%{$term}%");
        });
    }

    public function getIsConvertedAttribute(): bool
    {
        return $this->converted_to_client_id !== null;
    }
}
