<?php

namespace Modules\Leads\Services;

use App\Models\Setting;
use App\Services\AI\AiClientException;
use App\Services\AI\AiClientFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleMapsScraperService
{
    public function __construct(private AiClientFactory $aiFactory) {}

    private const SEARCH_TYPES = [
        'kebab', 'fast food', 'kurczak', 'burger', 'smażalnia', 'bar szybkiej obsługi', 'food truck',
    ];

    private const BIG_CHAINS = [
        'mcdonald', 'kfc', 'burger king', 'subway', 'starbucks', 'pizza hut', 'domino',
    ];

    public function searchCity(string $city, array $types = [], int $limit = 50): array
    {
        if (empty($types)) {
            $types = self::SEARCH_TYPES;
        }

        $allResults = [];
        $seenNames = [];

        foreach ($types as $type) {
            if (count($allResults) >= $limit) break;

            try {
                $results = $this->searchDuckDuckGo("$type $city restauracja lokale gastronomiczne");
                foreach ($results as $r) {
                    $nameKey = mb_strtolower(trim($r['name']));
                    if (isset($seenNames[$nameKey]) || $this->isBigChain($nameKey)) continue;
                    $seenNames[$nameKey] = true;
                    $r['source'] = 'google_maps';
                    $r['city'] = $city;
                    $allResults[] = $r;
                    if (count($allResults) >= $limit) break;
                }
            } catch (\Throwable $e) {
                Log::warning('DuckDuckGo scrape error', ['city' => $city, 'type' => $type, 'error' => $e->getMessage()]);
            }
        }

        return $allResults;
    }

    /**
     * Szukaj przez DuckDuckGo HTML (nie wymaga consent/JS) + GPT wyciąga nazwy lokali.
     */
    private function searchDuckDuckGo(string $query): array
    {
        $response = Http::timeout(15)->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ])->get('https://html.duckduckgo.com/html/', ['q' => $query]);

        if (!$response->successful()) return [];

        // Wyciągnij tytuły i snippety z wyników
        $html = $response->body();
        preg_match_all('/<a[^>]+class="result__a"[^>]*>(.*?)<\/a>/s', $html, $titles);
        preg_match_all('/<a[^>]+class="result__snippet"[^>]*>(.*?)<\/a>/s', $html, $snippets);

        if (empty($titles[1])) return [];

        // Zbuduj kontekst z wyników dla GPT
        $searchResults = [];
        for ($i = 0; $i < count($titles[1]); $i++) {
            $title = strip_tags(html_entity_decode($titles[1][$i] ?? '', ENT_QUOTES, 'UTF-8'));
            $snippet = strip_tags(html_entity_decode($snippets[1][$i] ?? '', ENT_QUOTES, 'UTF-8'));
            $searchResults[] = ($i + 1) . ". {$title} — {$snippet}";
        }

        $text = implode("\n", $searchResults);

        // GPT wyciąga nazwy restauracji z wyników wyszukiwania
        return $this->extractRestaurantsWithAI($text);
    }

    private function extractRestaurantsWithAI(string $searchResults): array
    {
        $prompt = <<<PROMPT
Z poniższych wyników wyszukiwania wyciągnij NAZWY KONKRETNYCH lokali gastronomicznych (restauracji, kebabów, fast foodów, barów).

ZASADY:
- Wyciągnij TYLKO nazwy własne konkretnych lokali (np. "Sultan Express", "Don Kebab", "Bambolejro")
- NIE wyciągaj nazw artykułów, rankingów, portali (np. "TOP 10 kebabów", "gdziejemy.pl", "Ranking 2026")
- NIE wyciągaj sieci: McDonald's, KFC, Burger King, Pizza Hut, Subway
- Jeśli w jednym wyniku jest wymieniony ranking — wyciągnij poszczególne nazwy lokali z opisu

Odpowiedz WYŁĄCZNIE JSON-em: [{"name": "Nazwa Lokalu"}, ...]
Jeśli nie ma konkretnych lokali, zwróć: []

WYNIKI WYSZUKIWANIA:
{$searchResults}
PROMPT;

        try {
            $content = $this->callAI($prompt);
            $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
            $content = preg_replace('/\s*```$/m', '', $content);
            $parsed = @json_decode(trim($content), true);

            if (!is_array($parsed)) return [];

            $results = [];
            foreach ($parsed as $item) {
                $name = trim($item['name'] ?? '');
                if ($name && mb_strlen($name) >= 3 && mb_strlen($name) <= 80) {
                    $results[] = [
                        'name' => $name,
                        'address' => null,
                        'phone' => null,
                        'website' => null,
                        'rating' => null,
                        'types' => '',
                    ];
                }
            }

            Log::info('DuckDuckGo+AI extracted', ['results' => count($results)]);
            return $results;
        } catch (\Throwable $e) {
            Log::warning('DuckDuckGo AI extract error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function callAI(string $prompt): string
    {
        $ai = $this->aiFactory->make();
        if (!$ai->isReady()) {
            throw new \RuntimeException('AI provider nie jest skonfigurowany.');
        }
        try {
            return $ai->chat($prompt, null, ['temperature' => 0.1, 'max_tokens' => 4096]);
        } catch (AiClientException $e) {
            throw new \RuntimeException('AI: ' . $e->getMessage(), 0, $e);
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
