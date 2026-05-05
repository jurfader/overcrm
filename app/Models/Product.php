<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sku', 'name', 'description', 'category', 'unit',
        'price_net', 'vat_rate', 'stock', 'track_stock', 'active',
    ];

    protected function casts(): array
    {
        return [
            'price_net'   => 'decimal:2',
            'vat_rate'    => 'integer',
            'stock'       => 'decimal:3',
            'track_stock' => 'boolean',
            'active'      => 'boolean',
        ];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (!$term) return $q;
        $term = '%' . trim($term) . '%';
        return $q->where(fn($w) => $w
            ->where('name', 'like', $term)
            ->orWhere('sku', 'like', $term)
            ->orWhere('category', 'like', $term)
        );
    }

    public function getPriceGrossAttribute(): float
    {
        return round((float)$this->price_net * (1 + $this->vat_rate / 100), 2);
    }
}
