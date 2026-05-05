<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicket extends Model
{
    protected $fillable = [
        'user_id', 'subject', 'category', 'message',
        'attach_log', 'status', 'email_error', 'meta', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'attach_log' => 'boolean',
            'meta'       => 'array',
            'sent_at'    => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
