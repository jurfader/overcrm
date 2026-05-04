<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = [
        'module',
        'group',
        'key',
        'value',
        'type',
        'label',
        'description',
        'options',
        'is_public',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_public' => 'boolean',
        ];
    }

    // ==================== STATYCZNE METODY ====================

    /**
     * Pobierz wartość ustawienia
     */
    public static function get(string $key, $default = null, string $module = 'core')
    {
        $cacheKey = "setting.{$module}.{$key}";

        return Cache::remember($cacheKey, 3600, function () use ($key, $default, $module) {
            $setting = self::where('module', $module)
                ->where('key', $key)
                ->first();

            if (!$setting) {
                return $default;
            }

            return self::castValue($setting->value, $setting->type);
        });
    }

    /**
     * Ustaw wartość ustawienia
     */
    public static function set(string $key, $value, string $module = 'core'): void
    {
        $setting = self::updateOrCreate(
            ['module' => $module, 'key' => $key],
            ['value' => is_array($value) ? json_encode($value) : $value]
        );

        Cache::forget("setting.{$module}.{$key}");
    }

    /**
     * Pobierz wszystkie ustawienia dla modułu
     */
    public static function getForModule(string $module): array
    {
        return self::where('module', $module)
            ->orderBy('group')
            ->orderBy('order')
            ->get()
            ->groupBy('group')
            ->toArray();
    }

    /**
     * Pobierz wszystkie ustawienia publiczne
     */
    public static function getPublic(): array
    {
        return self::where('is_public', true)
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * Rzutuj wartość na odpowiedni typ
     */
    protected static function castValue($value, string $type)
    {
        return match($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'float' => (float) $value,
            'json', 'array' => is_array($value) ? $value : json_decode($value, true),
            default => $value,
        };
    }

    // ==================== AKCESORY ====================

    /**
     * Pobierz sformatowaną wartość
     */
    public function getFormattedValueAttribute()
    {
        return self::castValue($this->value, $this->type);
    }
}
