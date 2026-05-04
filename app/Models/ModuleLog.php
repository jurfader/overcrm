<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleLog extends Model
{
    protected $fillable = [
        'module_id',
        'action',
        'version',
        'user_id',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActionLabelAttribute(): string
    {
        return match($this->action) {
            'installed' => 'Zainstalowano',
            'updated' => 'Zaktualizowano',
            'activated' => 'Aktywowano',
            'deactivated' => 'Dezaktywowano',
            'uninstalled' => 'Odinstalowano',
            'configured' => 'Zmieniono konfigurację',
            default => $this->action,
        };
    }
}
