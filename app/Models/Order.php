<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number', 'client_id', 'user_id', 'status',
        'order_date', 'delivery_date', 'notes',
        'total_net', 'total_vat', 'total_gross',
        'snapshot_company', 'snapshot_client',
    ];

    protected function casts(): array
    {
        return [
            'order_date'       => 'date',
            'delivery_date'    => 'date',
            'total_net'        => 'decimal:2',
            'total_vat'        => 'decimal:2',
            'total_gross'      => 'decimal:2',
            'snapshot_company' => 'array',
            'snapshot_client'  => 'array',
        ];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function items(): HasMany { return $this->hasMany(OrderItem::class)->orderBy('position'); }

    public function recalcTotals(): void
    {
        $totalNet = 0; $totalVat = 0; $totalGross = 0;
        foreach ($this->items as $item) {
            $totalNet   += (float) $item->total_net;
            $totalVat   += (float) $item->total_vat;
            $totalGross += (float) $item->total_gross;
        }
        $this->total_net   = round($totalNet, 2);
        $this->total_vat   = round($totalVat, 2);
        $this->total_gross = round($totalGross, 2);
        $this->save();
    }

    /** Generuj kolejny numer w formacie ZAM/YYYY/MM/NNN */
    public static function nextNumber(): string
    {
        $prefix = 'ZAM/' . now()->format('Y/m');
        $last = self::where('number', 'like', $prefix . '/%')
            ->orderBy('id', 'desc')
            ->value('number');

        $seq = 1;
        if ($last && preg_match('#/(\d+)$#', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }
        return sprintf('%s/%03d', $prefix, $seq);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft'       => 'Szkic',
            'new'         => 'Nowe',
            'in_progress' => 'W realizacji',
            'completed'   => 'Zrealizowane',
            'cancelled'   => 'Anulowane',
            default       => $this->status,
        };
    }
}
