<?php

namespace Modules\Infakt\Providers;

use App\Contracts\ProductProvider;
use Modules\Infakt\Services\InfaktService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ProductProvider read-only z katalogu inFakt. supportsManagement = false
 * (admin nie tworzy produktow z CRM, zarzadzanie w panelu inFakt).
 *
 * Ceny inFakt sa w groszach — konwertujemy na PLN dla CRM UI.
 */
class InfaktProductProvider implements ProductProvider
{
    public function __construct(protected InfaktService $infakt) {}

    public function key(): string { return 'infakt'; }
    public function label(): string { return 'inFakt.pl (katalog)'; }
    public function supportsManagement(): bool { return false; }

    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $all = $this->fetchAll($filters['q'] ?? null);

        $perPage = (int) ($filters['per_page'] ?? 50);
        $page = (int) request()->input('page', 1);
        $items = $all->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $all->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function search(string $term, int $limit = 20): Collection
    {
        $all = $this->fetchAll($term ?: null);
        return $all->take($limit)->values();
    }

    public function find(string|int $id): ?array
    {
        return $this->fetchAll()->firstWhere('id', $id);
    }

    protected function fetchAll(?string $namePrefix = null): Collection
    {
        try {
            $raw = $this->infakt->getProducts($namePrefix);
        } catch (\Throwable $e) {
            Log::warning('InfaktProductProvider fetch failed', ['error' => $e->getMessage()]);
            return collect();
        }

        return collect($raw)->map(fn ($p) => [
            'id'          => $p['id'] ?? null,
            'sku'         => $p['pkwiu'] ?? $p['symbol'] ?? null,
            'name'        => $p['name'] ?? '—',
            'description' => null,
            'category'    => null,
            'unit'        => $p['unit'] ?? 'szt',
            'price_net'   => (float) (($p['net_price'] ?? 0) / 100), // grosze -> zlote
            'vat_rate'    => (int) ($p['tax_symbol'] ?? 23),
            'stock'       => (float) ($p['quantity'] ?? 0),
            'track_stock' => isset($p['quantity']),
            'active'      => true,
        ])->filter(fn ($p) => $p['id']);
    }
}
