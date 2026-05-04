<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Serwis konwersji walut – używa kursów NBP.
 * Wszystkie kwoty w raportach powinny być w PLN.
 */
class CurrencyService
{
    private const NBP_API = 'https://api.nbp.pl/api/exchangerates/rates/a';
    private const CACHE_TTL = 86400; // 24h – NBP publikuje kursy raz dziennie

    /**
     * Przelicz kwotę na PLN.
     *
     * @param float $amount Kwota w walucie źródłowej
     * @param string $currency Kod waluty (PLN, EUR, USD, itd.)
     * @param float|null $exchangeRate Własny kurs (np. z faktury Fakturowni) – ma pierwszeństwo
     */
    public function toPln(float $amount, string $currency, ?float $exchangeRate = null): float
    {
        $currency = strtoupper(trim($currency ?: 'PLN'));

        if ($currency === 'PLN') {
            return $amount;
        }

        if ($exchangeRate !== null && $exchangeRate > 0) {
            return round($amount * $exchangeRate, 2);
        }

        $rate = $this->getExchangeRate($currency);
        if ($rate === null) {
            Log::warning("CurrencyService: brak kursu dla {$currency}, używam kwoty bez przeliczenia");
            return $amount;
        }

        return round($amount * $rate, 2);
    }

    /**
     * Pobierz kurs waluty do PLN z NBP (z cache).
     */
    public function getExchangeRate(string $currency): ?float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'PLN') {
            return 1.0;
        }

        $cacheKey = "nbp_rate_{$currency}_" . now()->format('Y-m-d');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($currency) {
            try {
                $response = Http::timeout(5)->get(
                    self::NBP_API . '/' . strtolower($currency) . '/last/1/',
                    ['format' => 'json']
                );

                if (!$response->successful()) {
                    return null;
                }

                $data = $response->json();
                $mid = $data['rates'][0]['mid'] ?? null;

                return $mid !== null ? (float) $mid : null;
            } catch (\Throwable $e) {
                Log::warning("CurrencyService NBP fetch failed: {$e->getMessage()}");
                return null;
            }
        });
    }
}
