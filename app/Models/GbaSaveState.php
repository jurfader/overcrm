<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GbaSaveState extends Model
{
    protected $fillable = ['user_id', 'rom_key', 'save_data', 'screenshot'];

    protected function casts(): array
    {
        return [
            'save_data' => 'binary',
            'screenshot' => 'binary',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
