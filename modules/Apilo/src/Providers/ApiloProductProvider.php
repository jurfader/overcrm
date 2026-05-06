<?php

namespace Modules\Apilo\Providers;

use App\Contracts\ProductProvider;
use App\Services\ApiloService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * ProductProvider implementation czytajacy katalog z Apilo API.
 *
 * Apilo nie ma full-text search po nazwie, wiec fetch'uje wszystkie produkty
 * + filtruje lokalnie. Cache w ApiloService::getProducts (zwykle 5 min TTL)
 * niweluje koszt powtornych requestow.
 *
 * supportsManagement = false — admin nie moze tworzyc/edytowac produktow Apilo
 * z poziomu CRM (zarzadzanie tylko w panelu Apilo). Tabela /admin/products
 * pokaze read-only listing gdy ten provider jest aktywny.
 */
class ApiloProductProvider implements ProductProvider
{
    public function __construct(protected ApiloService $apilo) {}

    public function key(): string { return 'apilo'; }
    public function label(): string { return 'Apilo (sync z API)'; }
    public function supportsManagement(): bool { return false; }

    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $all = $this->fetchAll();

        // Filtrowanie lokalne (Apilo nie ma server-side search)
        if (!empty($filters['q'])) {
            $term = mb_strtolower(trim($filters['q']));
            $all = $all->filter(fn ($p) => str_contains(mb_strtolower($p['name'] ?? ''), $term)
                || str_contains(mb_strtolower($p['sku'] ?? ''), $term));
        }
        if (!empty($filters['category'])) {
            $all = $all->filter(fn ($p) => ($p['category'] ?? null) === $filters['category']);
        }

        // Manualny paginator zeby UI dzialalo identycznie jak dla LocalProductProvider
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
        $all = $this->fetchAll();
        if ($term === '') {
            return $all->take($limit)->values();
        }
        $needle = mb_strtolower(trim($term));
        return $all
            ->filter(fn ($p) => str_contains(mb_strtolower($p['name'] ?? ''), $needle)
                || str_contains(mb_strtolower($p['sku'] ?? ''), $needle))
            ->take($limit)
            ->values();
    }

    public function find(string|int $id): ?array
    {
        $all = $this->fetchAll();
        return $all->firstWhere('id', $id) ?: $all->firstWhere('originalCode', $id);
    }

    /**
     * Zwraca wszystkie produkty Apilo zmappowane na shape kompatybilny z UI:
     *   { id, sku, name, unit, price_net, vat_rate, stock, track_stock, active, category }
     */
    protected function fetchAll(): Collection
    {
        try {
            $raw = $this->apilo->getProducts();
        } catch (\Throwable $e) {
            Log::warning('ApiloProductProvider fetch failed', ['error' => $e->getMessage()]);
            return collect();
        }

        return collect($raw)->map(fn ($p) => [
            'id'          => $p['id'] ?? $p['originalCode'] ?? null,
            'sku'         => $p['originalCode'] ?? $p['sku'] ?? null,
            'name'        => $p['name'] ?? '—',
            'description' => $p['description'] ?? null,
            'category'    => $p['category']['name'] ?? null,
            'unit'        => $p['unit'] ?? 'szt',
            'price_net'   => (float) ($p['priceWithTax'] ?? $p['price'] ?? 0) / (1 + ($p['tax'] ?? 23) / 100),
            'vat_rate'    => (int) ($p['tax'] ?? 23),
            'stock'       => (float) ($p['quantity'] ?? 0),
            'track_stock' => isset($p['quantity']),
            'active'      => ($p['status'] ?? 'active') === 'active',
        ])->filter(fn ($p) => $p['id']);
    }
}
