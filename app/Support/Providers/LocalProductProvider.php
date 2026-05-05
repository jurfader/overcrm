<?php

namespace App\Support\Providers;

use App\Contracts\ProductProvider;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Default core product provider — czyta z lokalnej tabeli `products` (Iteracja 1).
 */
class LocalProductProvider implements ProductProvider
{
    public function key(): string { return 'local'; }
    public function label(): string { return 'Lokalny magazyn (CORE)'; }
    public function supportsManagement(): bool { return true; }

    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        return Product::query()
            ->search($filters['q'] ?? null)
            ->when($filters['category'] ?? null, fn($q, $c) => $q->where('category', $c))
            ->when($filters['only_active'] ?? false, fn($q) => $q->active())
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 50);
    }

    public function search(string $term, int $limit = 20): Collection
    {
        return Product::query()
            ->active()
            ->search($term)
            ->limit($limit)
            ->get();
    }

    public function find(string|int $id): ?array
    {
        $product = Product::find($id);
        return $product?->toArray();
    }
}
