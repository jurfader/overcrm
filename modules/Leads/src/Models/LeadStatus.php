<?php

namespace Modules\Leads\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadStatus extends Model
{
    protected $fillable = [
        'name', 'slug', 'color', 'order', 'is_default', 'is_terminal', 'auto_rules',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_terminal' => 'boolean',
            'auto_rules' => 'array',
            'order' => 'integer',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'status_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
