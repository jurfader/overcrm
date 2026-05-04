<?php

namespace App\Services;

use App\Services\AI\AiClientException;
use App\Services\AI\AiClientFactory;

class VisitProfileAnalyzer
{
    public function __construct(private AiClientFactory $aiFactory) {}

    private function aiChat(string $systemPrompt, string $userContent, float $temperature = 0.3, int $maxTokens = 4096): string
    {
        $ai = $this->aiFactory->make();
        if (!$ai->isReady()) {
            throw new \RuntimeException('AI provider nie jest skonfigurowany. Sprawdź ustawienia w Admin → Moduły → Core → AI.');
        }

        try {
            return trim($ai->chat($userContent, $systemPrompt, [
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
            ]));
        } catch (AiClientException $e) {
            throw new \RuntimeException('AI: ' . $e->getMessage(), 0, $e);
        }
    }

    private const PROMPT = <<<'PROMPT'
Jesteś asystentem analizującym opisy wizyt handlowych w firmie Chicken King Family (dostawca panierki do chrupiącego kurczaka dla gastronomii B2B).

Na podstawie opisu wizyty i notatek wyciągnij informacje o profilu lokalu gastronomicznego. Zwróć TYLKO poprawny JSON bez żadnego dodatkowego tekstu, komentarzy ani bloków markdown.

Dozwolone wartości dla pól enum (używaj dokładnie tych kluczy):
- city_size: małe, średnie, duże
- location: centrum, osiedle, przy_drodze, galeria, dworzec, inne
- venue_type: stacjonarny, kontener, food_truck, przyczepa, wyspa, inne
- price_level: niski, średni, wysoki, premium
- platforms: pyszne, uber_eats, glovo, wolt, bolt_food, inne
- customer_profiles: turysci, mlodziez, studenci, rodziny, pracownicy, imprezy, koncerty, nocni
- decision_maker: wlasciciel, menedzer, kucharz, inny
- personality: szybki, spokojny, lubi_mowic, konkretny, analityczny, emocjonalny, negocjator

Struktura JSON do zwrócenia (pomiń sekcje, o których nie ma informacji; używaj null dla brakujących pól):
{
  "venue": {
    "city_size": null,
    "location": null,
    "venue_type": null,
    "venue_size": null,
    "kitchen_staff": null,
    "total_staff": null,
    "years_in_business": null,
    "venue_birthday": null
  },
  "concept": {
    "specialty": null,
    "cuisine": null,
    "price_level": null
  },
  "sales": {
    "delivery": null,
    "delivery_volume": null,
    "platforms": [],
    "rush_hours": null
  },
  "customers": {
    "profiles": []
  },
  "chicken": {
    "serves_chicken": null,
    "serving_form": null,
    "volume": null
  },
  "kitchen": {
    "own_production": null,
    "uses_semi_finished": null,
    "suppliers": null
  },
  "organization": {
    "decision_maker": null,
    "ordering_person": null,
    "ordering_frequency": null
  },
  "mental": {
    "personality": [],
    "approach_notes": null
  },
  "potential": {
    "promo_activities": null,
    "media_quality": null,
    "current_products": null,
    "menu_changes": null,
    "open_to_tests": null,
    "notes": null
  }
}

Zasady:
- Wyciągaj TYLKO to, co jest wyraźnie wspomniane lub można logicznie wywnioskować z tekstu
- Dla pól tekstowych (specialty, cuisine, suppliers itd.) używaj krótkich, konkretnych wartości
- Dla tablic (platforms, profiles, personality) dodawaj tylko wartości, o których mowa w tekście
- Dla boolean (delivery, serves_chicken, own_production) ustaw true/false tylko gdy jest to jasne z kontekstu
- Nie wymyślaj danych – jeśli czegoś nie ma w tekście, zostaw null lub pustą tablicę
- venue_birthday: data otwarcia/urodzin lokalu (np. rocznica otwarcia). Format YYYY-MM-DD. Wyciągaj tylko gdy wyraźnie podana (np. "otwarte 15.03.2020", "5 lat od otwarcia w marcu 2019", "rocznica 12 kwietnia")
PROMPT;

    public function analyze(string $description, string $notes = ''): array
    {
        $text = trim($description . "\n\n" . $notes);
        if (empty($text)) {
            return $this->emptyProfile();
        }

        $content = $this->aiChat(self::PROMPT, $text, 0.2, 4096);
        if (empty($content)) {
            return $this->emptyProfile();
        }

        // Usuń ewentualne bloki markdown
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/\s*```\s*$/', '', $content);

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Nie udało się sparsować odpowiedzi AI: ' . json_last_error_msg());
        }

        return $this->mergeWithDefaults($decoded);
    }

    /**
     * Generuje podsumowanie wizyty na podstawie opisu, notatek i profilu lokalu.
     */
    public function generateSummary(string $description, string $notes = '', array $profile = []): string
    {
        $descPlain = trim(strip_tags($description));
        $notesPlain = trim($notes);
        $textParts = array_filter([$descPlain, $notesPlain]);
        $visitText = implode("\n\n", $textParts);

        $profileJson = $this->profileToReadableJson($profile);

        if (empty($visitText) && empty($profileJson)) {
            return '';
        }

        $userContent = "Na podstawie poniższych informacji napisz zwięzłe podsumowanie wizyty handlowej (2–4 zdania).\n\n";
        if (!empty($visitText)) {
            $userContent .= "--- OPIS I NOTATKI WIZYTY ---\n" . $visitText . "\n\n";
        }
        if (!empty($profileJson)) {
            $userContent .= "--- PROFIL LOKALU (karta klienta) ---\n" . $profileJson . "\n\n";
        }
        $userContent .= "Podsumowanie powinno łączyć kluczowe informacje z opisu i profilu w spójną całość.";

        $systemPrompt = 'Jesteś asystentem tworzącym zwięzłe podsumowania wizyt handlowych w firmie Chicken King Family (dostawca panierki do chrupiącego kurczaka dla gastronomii B2B). Odpowiadaj wyłącznie tekstem podsumowania, bez nagłówków ani dodatkowych komentarzy.';

        return $this->aiChat($systemPrompt, $userContent, 0.3, 4096);
    }

    private function profileToReadableJson(array $profile): string
    {
        $filtered = [];
        foreach ($profile as $section => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $sectionData = [];
            foreach ($fields as $key => $val) {
                if ($val === null || $val === '' || $val === false) {
                    continue;
                }
                if (is_array($val) && empty($val)) {
                    continue;
                }
                $sectionData[$key] = $val;
            }
            if (!empty($sectionData)) {
                $filtered[$section] = $sectionData;
            }
        }
        return empty($filtered) ? '' : json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function emptyProfile(): array
    {
        return [
            'venue' => ['city_size' => '', 'location' => '', 'venue_type' => '', 'venue_size' => '', 'kitchen_staff' => '', 'total_staff' => '', 'years_in_business' => '', 'venue_birthday' => ''],
            'concept' => ['specialty' => '', 'cuisine' => '', 'price_level' => ''],
            'sales' => ['delivery' => false, 'delivery_volume' => '', 'platforms' => [], 'rush_hours' => ''],
            'customers' => ['profiles' => []],
            'chicken' => ['serves_chicken' => false, 'serving_form' => '', 'volume' => ''],
            'kitchen' => ['own_production' => false, 'uses_semi_finished' => false, 'suppliers' => ''],
            'organization' => ['decision_maker' => '', 'ordering_person' => '', 'ordering_frequency' => ''],
            'mental' => ['personality' => [], 'approach_notes' => ''],
            'potential' => ['promo_activities' => '', 'media_quality' => '', 'current_products' => '', 'menu_changes' => false, 'open_to_tests' => false, 'notes' => ''],
        ];
    }

    private function mergeWithDefaults(array $decoded): array
    {
        $default = $this->emptyProfile();
        foreach ($default as $section => $fields) {
            if (!isset($decoded[$section]) || !is_array($decoded[$section])) {
                continue;
            }
            foreach ($fields as $key => $defaultVal) {
                if (!array_key_exists($key, $decoded[$section])) {
                    continue;
                }
                $val = $decoded[$section][$key];
                if ($val === null) {
                    continue;
                }
                if (is_array($defaultVal)) {
                    $default[$section][$key] = is_array($val) ? $val : [];
                } elseif (is_bool($defaultVal)) {
                    $default[$section][$key] = (bool) $val;
                } elseif (is_numeric($defaultVal) || $key === 'kitchen_staff' || $key === 'total_staff' || $key === 'years_in_business') {
                    $default[$section][$key] = is_numeric($val) ? (int) $val : '';
                } elseif ($key === 'venue_birthday') {
                    $default[$section][$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $val) ? (string) $val : '';
                } else {
                    $default[$section][$key] = (string) $val;
                }
            }
        }
        return $default;
    }
}
