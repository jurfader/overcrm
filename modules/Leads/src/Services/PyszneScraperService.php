<?php

namespace Modules\Leads\Services;

use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;

class PyszneScraperService
{
    private const BIG_CHAINS = [
        'mcdonald', 'kfc', 'burger king', 'subway', 'starbucks', 'pizza hut', 'domino',
    ];

    public function searchCity(string $city, int $limit = 50): array
    {
        $slug = $this->cityToSlug($city);
        $url = "https://www.pyszne.pl/na-dowoz/jedzenie/{$slug}";

        try {
            // Pyszne.pl to SPA — Puppeteer renderuje JS i scrolluje żeby załadować restauracje
            $json = Browsershot::url($url)
                ->noSandbox()
                ->windowSize(1200, 5000)
                ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                ->waitUntilNetworkIdle()
                ->setDelay(3000)
                ->timeout(30)
                ->evaluate(<<<'JS'
                    (() => {
                        const restaurants = [];
                        const seen = new Set();
                        document.querySelectorAll('a[href*="/menu/"]').forEach(a => {
                            const name = a.textContent.trim().split('\n')[0].trim();
                            if (!name || name.length < 3 || name.length > 80 || seen.has(name)) return;
                            seen.add(name);
                            const href = a.getAttribute('href') || '';
                            restaurants.push({ name, href });
                        });
                        return JSON.stringify(restaurants);
                    })()
                JS);

            $restaurants = @json_decode($json, true);

            if (!is_array($restaurants) || empty($restaurants)) {
                Log::info('Pyszne.pl: brak wyników', ['city' => $city, 'raw' => substr($json ?? '', 0, 200)]);
                return [];
            }

            $results = [];
            foreach ($restaurants as $r) {
                $name = $r['name'] ?? '';
                if ($this->isBigChain(mb_strtolower($name))) continue;

                $results[] = [
                    'name' => $name,
                    'address' => null,
                    'phone' => null,
                    'website' => $r['href'] ? "https://www.pyszne.pl{$r['href']}" : null,
                    'rating' => null,
                    'types' => '',
                    'source' => 'pyszne',
                    'city' => $city,
                ];
            }

            Log::info('Pyszne.pl scraped', ['city' => $city, 'results' => count($results)]);
            return array_slice($results, 0, $limit);
        } catch (\Throwable $e) {
            Log::warning('Pyszne.pl scrape error', ['city' => $city, 'error' => $e->getMessage()]);
            return [];
        }
    }

    private function cityToSlug(string $city): string
    {
        $slug = mb_strtolower($city);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z', ' ' => '-'];
        return strtr($slug, $map);
    }

    private function isBigChain(string $nameLower): bool
    {
        foreach (self::BIG_CHAINS as $chain) {
            if (str_contains($nameLower, $chain)) return true;
        }
        return false;
    }
}
