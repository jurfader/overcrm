<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameSetting extends Model
{
    protected $fillable = ['game_id', 'key', 'value'];

    public static function get(string $gameId, string $key, mixed $default = null): mixed
    {
        $s = static::where('game_id', $gameId)->where('key', $key)->first();

        return $s ? $s->value : $default;
    }

    public static function set(string $gameId, string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['game_id' => $gameId, 'key' => $key],
            ['value' => $value]
        );
    }

    public static function getAll(string $gameId): array
    {
        return static::where('game_id', $gameId)
            ->get()
            ->pluck('value', 'key')
            ->toArray();
    }
}
