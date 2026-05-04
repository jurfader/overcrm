<?php

namespace Modules\Leads\Services;

use App\Models\Setting;
use App\Services\AI\AiClientException;
use App\Services\AI\AiClientFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Szuka restauracji z platform delivery (Glovo, Uber Eats, Wolt) przez DuckDuckGo + AI.
 */
class DeliveryPlatformScraperService
{
    public function __construct(private AiClientFactory $aiFactory) {}

    private const PLATFORMS = [
        'glovo' => 'site:glovoapp.com/pl',
        'ubereats' => 'site:ubereats.com/pl',
        'wolt' => 'site:wolt.com/pl',
    ];

    private const BIG_CHAINS = [
        'mcdonald', 'kfc', 'burger king', 'subway', 'starbucks', 'pizza hut', 'domino',
    ];

    public function searchCity(string $city, int $limit = 30): array
    {
        $allResults = [];
        $seenNames = [];

        foreach (self::PLATFORMS as $platform => $siteFilter) {
            if (count($allResults) >= $limit) break;

            try {
                $query = "restauracja kebab fast food {$city} {$siteFilter}";
                $names = $this->searchDuckDuckGoAndExtract($query);

                foreach ($names as $name) {
                    $nameKey = mb_strtolower(trim($name));
                    if (isset($seenNames[$nameKey]) || $this->isBigChain($nameKey)) continue;
                    $seenNames[$nameKey] = true;

                    $allResults[] = [
                        'name' => $name,
                        'address' => null,
                        'phone' => null,
                        'website' => null,
                        'email' => null,
                        'rating' => null,
                        'types' => '',
                        'source' => $platform,
                        'city' => $city,
                    ];
                }

                Log::info("Delivery platform scraped", ['platform' => $platform, 'city' => $city, 'results' => count($names)]);
            } catch (\Throwable $e) {
                Log::warning("Delivery scrape error", ['platform' => $platform, 'city' => $city, 'error' => $e->getMessage()]);
            }
        }

        return array_slice($allResults, 0, $limit);
    }

    private function searchDuckDuckGoAndExtract(string $query): array
    {
        $response = Http::timeout(15)->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ])->get('https://html.duckduckgo.com/html/', ['q' => $query]);

        if (!$response->successful()) return [];

        preg_match_all('/<a[^>]+class="result__a"[^>]*>(.*?)<\/a>/s', $response->body(), $titles);
        preg_match_all('/<a[^>]+class="result__snippet"[^>]*>(.*?)<\/a>/s', $response->body(), $snippets);

        if (empty($titles[1])) return [];

        $searchText = '';
        for ($i = 0; $i < count($titles[1]); $i++) {
            $title = strip_tags(html_entity_decode($titles[1][$i] ?? '', ENT_QUOTES, 'UTF-8'));
            $snippet = strip_tags(html_entity_decode($snippets[1][$i] ?? '', ENT_QUOTES, 'UTF-8'));
            $searchText .= "{$title} — {$snippet}\n";
        }

        return $this->extractNamesWithAI($searchText);
    }

    private function extractNamesWithAI(string $text): array
    {
        $prompt = <<<PROMPT
Z poniższych wyników wyszukiwania wyciągnij NAZWY KONKRETNYCH lokali gastronomicznych.
Wyciągaj TYLKO nazwy własne restauracji/kebabów/fast foodów (np. "Sultan Express", "Bambolejro").
NIE wyciągaj: nazw artykułów, rankingów, portali, sieci (McDonald's, KFC, Burger King).
Odpowiedz WYŁĄCZNIE JSON-em: ["Nazwa 1", "Nazwa 2", ...]

WYNIKI:
{$text}
PROMPT;

        try {
            $ai = $this->aiFactory->make();
            if (!$ai->isReady()) {
                Log::info('DeliveryPlatform AI extract — provider not configured, fallback []');
                return [];
            }
            try {
                $content = $ai->chat($prompt, null, ['temperature' => 0.1, 'max_tokens' => 4096]);
            } catch (AiClientException $e) {
                Log::warning('DeliveryPlatform AI extract failed', ['error' => $e->getMessage()]);
                return [];
            }

            $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
            $content = preg_replace('/\s*```$/m', '', $content);
            $names = @json_decode(trim($content), true);

            return is_array($names) ? array_filter($names, fn($n) => is_string($n) && mb_strlen($n) >= 3) : [];
        } catch (\Throwable $e) {
            Log::warning('Delivery AI extract error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function isBigChain(string $nameLower): bool
    {
        foreach (self::BIG_CHAINS as $chain) {
            if (str_contains($nameLower, $chain)) return true;
        }
        return false;
    }
}
