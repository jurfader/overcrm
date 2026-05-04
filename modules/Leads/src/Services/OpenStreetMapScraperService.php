<?php

namespace Modules\Leads\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenStreetMapScraperService
{
    private const OVERPASS_URL = 'https://overpass-api.de/api/interpreter';

    private const BIG_CHAINS = [
        'mcdonald', 'kfc', 'burger king', 'subway', 'starbucks', 'pizza hut', 'domino',
    ];

    /**
     * Szukaj restauracji/fast foodów w mieście przez OpenStreetMap Overpass API.
     * Darmowe, nie wymaga klucza API, działa dla każdego miasta.
     */
    public function searchCity(string $city, int $limit = 50): array
    {
        try {
            $query = <<<OVERPASS
[out:json][timeout:20];
area["name"="{$city}"]["admin_level"~"^[678]$"]->.a;
(
  node["amenity"="fast_food"](area.a);
  node["amenity"="restaurant"](area.a);
  node["amenity"="bar"]["food"="yes"](area.a);
  way["amenity"="fast_food"](area.a);
  way["amenity"="restaurant"](area.a);
);
out body;
OVERPASS;

            $response = Http::timeout(25)->asForm()->post(self::OVERPASS_URL, [
                'data' => $query,
            ]);

            if (!$response->successful()) {
                Log::warning('Overpass API error', ['city' => $city, 'status' => $response->status()]);
                return [];
            }

            $data = $response->json();
            $elements = $data['elements'] ?? [];

            $results = [];
            $seenNames = [];

            foreach ($elements as $el) {
                $tags = $el['tags'] ?? [];
                $name = trim($tags['name'] ?? '');

                if (empty($name) || mb_strlen($name) < 3) continue;

                $nameKey = mb_strtolower($name);
                if (isset($seenNames[$nameKey])) continue;
                if ($this->isBigChain($nameKey)) continue;
                $seenNames[$nameKey] = true;

                $phone = $tags['phone'] ?? $tags['contact:phone'] ?? null;
                $website = $tags['website'] ?? $tags['contact:website'] ?? null;
                $email = $tags['email'] ?? $tags['contact:email'] ?? null;

                // Formatuj adres
                $address = implode(' ', array_filter([
                    $tags['addr:street'] ?? null,
                    $tags['addr:housenumber'] ?? null,
                ]));
                if (!empty($tags['addr:postcode']) || !empty($tags['addr:city'])) {
                    $address .= ($address ? ', ' : '') . implode(' ', array_filter([
                        $tags['addr:postcode'] ?? null,
                        $tags['addr:city'] ?? null,
                    ]));
                }

                $cuisine = $tags['cuisine'] ?? '';
                $amenity = $tags['amenity'] ?? '';

                $results[] = [
                    'name' => $name,
                    'address' => $address ?: null,
                    'phone' => $phone ? $this->normalizePhone($phone) : null,
                    'website' => $website,
                    'email' => $email,
                    'rating' => null,
                    'types' => $cuisine ?: ($amenity === 'fast_food' ? 'fast food' : 'restauracja'),
                    'source' => 'openstreetmap',
                    'city' => $city,
                ];

                if (count($results) >= $limit) break;
            }

            Log::info('OpenStreetMap scraped', ['city' => $city, 'elements' => count($elements), 'results' => count($results)]);
            return $results;
        } catch (\Throwable $e) {
            Log::warning('OpenStreetMap scrape error', ['city' => $city, 'error' => $e->getMessage()]);
            return [];
        }
    }

    private function normalizePhone(string $phone): string
    {
        // Wyciągnij pierwszy numer jeśli jest kilka
        $phone = explode(';', $phone)[0];
        return trim($phone);
    }

    private function isBigChain(string $nameLower): bool
    {
        foreach (self::BIG_CHAINS as $chain) {
            if (str_contains($nameLower, $chain)) return true;
        }
        return false;
    }
}
