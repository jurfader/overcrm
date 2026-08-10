<?php

namespace App\Contracts;

use App\Models\Order;

/**
 * Abstrakcja wysyłki. Kategoria celowo rozdziela DWIE różne zdolności, bo
 * dostawcy pokrywają je nierówno:
 *
 *  - wybór punktu odbioru (paczkomat, punkt kurierski) — sam widget na froncie,
 *    bez żadnego API; tyle dziś potrafi moduł InPost,
 *  - nadanie przesyłki i śledzenie — pełna integracja z API przewoźnika.
 *
 * Gdyby kontrakt zakładał, że każdy dostawca umie wszystko, moduł zamówień
 * pokazywałby przycisk „Nadaj przesyłkę" tam, gdzie nie ma go czym obsłużyć.
 * Dlatego caller MUSI sprawdzić supports*() przed użyciem.
 *
 * Providery: InPost (dziś tylko punkty), docelowo Furgonetka (broker — jedna
 * integracja daje DPD, DHL, GLS i resztę), DPD i DHL bezpośrednio.
 */
interface ShippingProvider
{
    public function key(): string;

    public function label(): string;

    public function isAvailable(): bool;

    /** Czy dostawca potrafi pokazać mapę punktów odbioru. */
    public function supportsPointPicking(): bool;

    /** Czy dostawca potrafi nadać przesyłkę przez API. */
    public function supportsShipments(): bool;

    /**
     * Konfiguracja widgetu wyboru punktu dla frontu (token, identyfikator
     * organizacji, ograniczenia). Pusta tablica, gdy dostawca nie ma punktów.
     *
     * @return array<string, mixed>
     */
    public function pointWidgetConfig(): array;

    /**
     * Nadaje przesyłkę dla zamówienia. Zwraca:
     * ['tracking_number' => string, 'label_url' => string|null, 'external_id' => string|null]
     *
     * @param array<string, mixed> $options wymiary, waga, punkt odbioru, pobranie
     * @return array<string, mixed>
     * @throws \RuntimeException gdy dostawca nie wspiera nadawania
     */
    public function createShipment(Order $order, array $options = []): array;

    /**
     * Status przesyłki. Zwraca null, gdy dostawca nie potrafi śledzić
     * albo numer jest nieznany.
     *
     * @return array{status: string, description: string|null, updated_at: string|null}|null
     */
    public function trackShipment(string $trackingNumber): ?array;
}
