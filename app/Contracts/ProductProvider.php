<?php

namespace App\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Abstrakcja źródła produktów. Core (CORE provider 'local') czyta z lokalnej
 * tabeli `products`. Moduły (Apilo, BaseLinker) implementują ten sam interface
 * pobierając dane z zewnętrznych API.
 *
 * Admin wybiera aktywnego providera w Settings → Integracje. Wszystkie
 * controllery używają abstract `app(ProductProvider::class)` — automatycznie
 * dostają aktywnego providera (binding w ProviderServiceProvider).
 */
interface ProductProvider
{
    /**
     * Klucz tego providera (np. 'local', 'apilo', 'baselinker'). Stały.
     */
    public function key(): string;

    /**
     * Czytelna nazwa do wyświetlenia w UI selectora (np. "Lokalny CORE", "Apilo (sync API)").
     */
    public function label(): string;

    /**
     * Lista produktów z opcjonalnym filtrowaniem (q, category, only_active, page).
     * Zwraca paginator albo Collection (depending on provider — admin paginuje, sync API może zwracać batch).
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection;

    /**
     * Wyszukaj produkty po nazwie/SKU — używane w autocomplete formularzy zamówień.
     */
    public function search(string $term, int $limit = 20): Collection;

    /**
     * Pojedynczy produkt po ID. Zwraca array (nie model) żeby external providers nie musieli ich tworzyć w lokalnej DB.
     */
    public function find(string|int $id): ?array;

    /**
     * Czy ten provider obsługuje CRUD (admin może dodać/edytować/usunąć produkt z UI)?
     * Local = true. External (Apilo) = zwykle false (zarządzanie tylko w panelu Apilo).
     */
    public function supportsManagement(): bool;
}
