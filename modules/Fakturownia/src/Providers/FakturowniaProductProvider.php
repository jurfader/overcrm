<?php

namespace Modules\Fakturownia\Providers;

use App\Contracts\ProductProvider;
use Modules\Fakturownia\Services\FakturowniaService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class FakturowniaProductProvider implements ProductProvider
{
    public function __construct(protected FakturowniaService $fakturownia) {}

    public function key(): string { return 'fakturownia'; }
    public function label(): string { return 'Fakturownia.pl (katalog)'; }
    public function supportsManagement(): bool { return false; }

    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $all = $this->fetchAll($filters['q'] ?? null);

        $perPage = (int)($filters['per_page'] ?? 50);
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
            $raw = $this->fakturownia->getProducts($namePrefix);
        } catch (\Throwable $e) {
            Log::warning('FakturowniaProductProvider fetch failed', ['error' => $e->getMessage()]);
            return collect();
        }

        return collect($raw)->map(fn ($p) => [
            'id'          => $p['id'] ?? null,
            'sku'         => $p['code'] ?? $p['sku'] ?? null,
            'name'        => $p['name'] ?? '—',
            'description' => $p['description'] ?? null,
            'category'    => null,
            'unit'        => $p['unit'] ?? 'szt',
            'price_net'   => (float) ($p['price_net'] ?? $p['price'] ?? 0),
            'vat_rate'    => (int) ($p['tax'] ?? 23),
            'stock'       => 0,
            'track_stock' => false, // Fakturownia nie ma stanu magazynowego (to katalog dla faktur)
            'active'      => true,
        ])->filter(fn ($p) => $p['id']);
    }
}
