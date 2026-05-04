<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'is_public',
        'sync_from_fakturownia',
        'fakturownia_prefix',
        'html_content',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sync_from_fakturownia' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
