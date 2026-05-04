<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientSummary extends Model
{
    protected $fillable = [
        'client_id',
        'client_visit_id',
        'summary',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clientVisit(): BelongsTo
    {
        return $this->belongsTo(ClientVisit::class, 'client_visit_id');
    }
}
