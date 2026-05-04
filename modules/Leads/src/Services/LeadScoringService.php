<?php

namespace Modules\Leads\Services;

use App\Models\Setting;
use App\Services\AI\AiClientException;
use App\Services\AI\AiClientFactory;
use App\Services\FakturowniaService;
use Illuminate\Support\Facades\Log;
use Modules\Leads\Models\Lead;

class LeadScoringService
{
    private ?array $existingClients = null;

    public function __construct(private AiClientFactory $aiFactory) {}

    /**
     * Oceń i filtruj surowe wyniki scraperów.
     * Zwraca tablicę wyników z oceną AI i statusem duplikatu.
     */
    public function scoreAndFilter(array $rawResults): array
    {
        // 1. Filtruj duplikaty z bazy leadów
        $filtered = $this->filterExistingLeads($rawResults);

        // 2. Sprawdź w Fakturowni
        $filtered = $this->markFakturowniaClients($filtered);

        // 3. Oceń z GPT (batch — max 20 na raz)
        $scored = [];
        foreach (array_chunk($filtered, 20) as $chunk) {
            $scored = array_merge($scored, $this->scoreBatch($chunk));
        }

        // 4. Sortuj po score malejąco
        usort($scored, fn($a, $b) => ($b['ai_score'] ?? 0) <=> ($a['ai_score'] ?? 0));

        return $scored;
    }

    private function filterExistingLeads(array $results): array
    {
        return array_values(array_filter($results, function ($r) {
            $name = mb_strtolower(trim($r['name'] ?? ''));

            // Sprawdź po nazwie + mieście w bazie leadów
            $exists = Lead::where(function ($q) use ($r, $name) {
                $q->whereRaw('LOWER(name) = ?', [$name])
                  ->orWhereRaw('LOWER(company_name) = ?', [$name]);
                if (!empty($r['phone'])) {
                    $q->orWhere('phone', 'like', '%' . substr(preg_replace('/\D/', '', $r['phone']), -9));
                }
            })->exists();

            if ($exists) {
                $r['_duplicate'] = 'lead';
            }

            return !$exists;
        }));
    }

    private function markFakturowniaClients(array $results): array
    {
        $this->loadFakturowniaClients();

        foreach ($results as &$r) {
            $r['is_existing_client'] = false;
            $name = mb_strtolower(trim($r['name'] ?? ''));

            foreach ($this->existingClients as $client) {
                $clientName = mb_strtolower(trim($client['name'] ?? ''));

                // Porównanie po nazwie (pełna lub częściowa)
                $matched = false;
                if ($name && $clientName && (
                    $name === $clientName
                    || str_contains($clientName, $name)
                    || str_contains($name, $clientName)
                    || similar_text($name, $clientName) > max(mb_strlen($name), mb_strlen($clientName)) * 0.7
                )) {
                    $matched = true;
                }

                // Porównanie po telefonie
                if (!$matched && !empty($r['phone']) && !empty($client['phone'])) {
                    $rPhone = substr(preg_replace('/\D/', '', $r['phone']), -9);
                    $cPhone = substr(preg_replace('/\D/', '', $client['phone']), -9);
                    if ($rPhone === $cPhone && strlen($rPhone) >= 9) {
                        $matched = true;
                    }
                }

                if ($matched) {
                    $r['is_existing_client'] = true;
                    $r['fakturownia_client'] = $client['name'];
                    // Wzbogać leada o dane z Fakturowni
                    if (empty($r['phone']) && !empty($client['phone'])) {
                        $r['phone'] = $client['phone'];
                    }
                    if (empty($r['email']) && !empty($client['email'])) {
                        $r['email'] = $client['email'];
                    }
                    break;
                }
            }
        }

        return $results;
    }

    private function loadFakturowniaClients(): void
    {
        if ($this->existingClients !== null) return;

        try {
            $fakturownia = app(FakturowniaService::class);
            if ($fakturownia->isConfigured()) {
                $this->existingClients = $fakturownia->getSalesClients();
            } else {
                $this->existingClients = [];
            }
        } catch (\Throwable $e) {
            Log::warning('LeadScoring: Fakturownia error', ['error' => $e->getMessage()]);
            $this->existingClients = [];
        }
    }

    private function scoreBatch(array $results): array
    {
        if (empty($results)) return [];

        $listings = [];
        foreach ($results as $i => $r) {
            $listings[] = ($i + 1) . ". {$r['name']}" .
                ($r['types'] ? " ({$r['types']})" : '') .
                ($r['city'] ? " — {$r['city']}" : '') .
                ($r['address'] ? ", {$r['address']}" : '') .
                ($r['rating'] ? " [ocena: {$r['rating']}]" : '') .
                ($r['is_existing_client'] ? " [JUŻ KLIENT W FAKTUROWNI: {$r['fakturownia_client']}]" : '');
        }

        $listText = implode("\n", $listings);

        $systemPrompt = <<<'SYS'
Jesteś ekspertem sprzedaży Chicken King Family — producenta i dostawcy produktów dla gastronomii B2B.

## NASZA OFERTA
- Panierki do chrupiącego kurczaka, ryb i warzyw (styl KFC — chrupiący kurczak w 3 minuty)
- Marynaty do chrupiącego kurczaka (pikantna, łagodna)
- Frytury profesjonalne do smażenia
- Sosy firmowe
- Opakowania z personalizacją dla restauracji (boxy, kubełki, torby papierowe, tortille)
- Wsparcie marketingowe (materiały, ulotki, social media)

## OCENA POTENCJAŁU
Oceń każdy lokal z listy pod kątem potencjału jako klient Chicken King.

IDEALNI KLIENCI (score 8-10): kebaby, fast foody, smażalnie, lokale z kurczakiem panierowanym, food trucki z fast foodem, bary szybkiej obsługi, lokale z frytownicą
DOBRZY KLIENCI (score 5-7): burgerownie (mogą dodać kurczaka), restauracje z frytkami/panierowanym, bistro, bary, puby z jedzeniem, food court
ŚREDNI KLIENCI (score 3-4): restauracje z szerokim menu (mogą dodać kurczaka jako danie), catering
SŁABI KLIENCI (score 1-2): pizzerie (tylko pizza), sushi, kuchnia azjatycka (wok), wegetariańskie, kawiarnie, piekarnie, lodziarnie, cukiernie
JUŻ KLIENCI (score 0): lokale oznaczone jako [JUŻ KLIENT W FAKTUROWNI] — daj score 0

Mniejsze sieciówki (2-10 lokali) to DOBRZY klienci — mogą zamówić na wiele punktów.
Duże sieciówki (McDonald's, KFC, Burger King, Subway, Pizza Hut) → chain: true, score: 1

Odpowiedz WYŁĄCZNIE JSON-em:
[{"nr": 1, "score": 8, "reason": "Kebab — idealny profil, smaży kurczaka", "chain": false}, ...]
SYS;

        $userPrompt = "Oceń te lokale:\n\n{$listText}";

        try {
            $content = $this->callAI($systemPrompt, $userPrompt);

            $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
            $content = preg_replace('/\s*```$/m', '', $content);

            $scores = json_decode(trim($content), true);

            if (is_array($scores)) {
                foreach ($scores as $score) {
                    $idx = ($score['nr'] ?? 0) - 1;
                    if (isset($results[$idx])) {
                        $results[$idx]['ai_score'] = $score['score'] ?? 0;
                        $results[$idx]['ai_reason'] = $score['reason'] ?? '';
                        $results[$idx]['ai_chain'] = $score['chain'] ?? false;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('LeadScoring GPT error', ['error' => $e->getMessage()]);
            // Fallback — daj domyślny score
            foreach ($results as &$r) {
                $r['ai_score'] = $r['is_existing_client'] ? 0 : 5;
                $r['ai_reason'] = 'Ocena automatyczna (błąd AI)';
            }
        }

        return $results;
    }

    /**
     * Wywołaj AI przez globalny AiClientFactory (Settings: core.ai_provider).
     */
    private function callAI(string $systemPrompt, string $userPrompt): string
    {
        $ai = $this->aiFactory->make();
        if (!$ai->isReady()) {
            throw new \RuntimeException('AI provider nie jest skonfigurowany. Sprawdź ustawienia w Admin → Moduły → Core → AI.');
        }

        try {
            return $ai->chat($userPrompt, $systemPrompt, [
                'temperature' => 0.2,
                'max_tokens' => 4096,
            ]);
        } catch (AiClientException $e) {
            throw new \RuntimeException('AI: ' . $e->getMessage(), 0, $e);
        }
    }
}
