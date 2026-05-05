<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'name', 'sku', 'unit',
        'quantity', 'price_net', 'vat_rate',
        'total_net', 'total_vat', 'total_gross', 'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity'    => 'decimal:3',
            'price_net'   => 'decimal:2',
            'vat_rate'    => 'integer',
            'total_net'   => 'decimal:2',
            'total_vat'   => 'decimal:2',
            'total_gross' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }

    /** Przelicz totale tej pozycji z quantity, price_net, vat_rate */
    public function recalc(): void
    {
        $net = round((float)$this->quantity * (float)$this->price_net, 2);
        $vat = round($net * ($this->vat_rate / 100), 2);
        $this->total_net   = $net;
        $this->total_vat   = $vat;
        $this->total_gross = round($net + $vat, 2);
    }
}
