<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientVisit;
use App\Services\AI\AiClientException;
use App\Services\AI\AiClientFactory;
use Illuminate\Support\Facades\Schema;

class CallReminderService
{
    private const PROMPT = <<<'PROMPT'
Jesteś asystentem dla handlowca firmy Chicken King Family (dostawca panierki do chrupiącego kurczaka dla gastronomii B2B).

Przygotuj krótką, praktyczną "rozmówkę" przed telefonem do klienta – 3-5 konkretnych punktów. Skup się na:
- o czym przypomnieć (ostatnia rozmowa, wizyta)
- co zaproponować lub zapytać
- na co zwrócić uwagę (np. preferencje klienta, wcześniejsze ustalenia)

Odpowiedz TYLKO w formacie zwykłego tekstu, punkt po punkcie (każdy punkt w nowej linii z myślnikiem). Bez wstępu, bez podsumowania. Maksymalnie 5 zwięzłych punktów.
PROMPT;

    public function __construct(private AiClientFactory $aiFactory) {}

    public function generate(Client $client): string
    {
        $context = $this->buildContext($client);
        if (empty(trim($context))) {
            return "Brak danych kontekstowych. Przypomnij się, zapytaj o potrzeby i zaproponuj spotkanie lub ofertę.";
        }

        $ai = $this->aiFactory->make();
        if (!$ai->isReady()) {
            throw new \RuntimeException('AI provider nie jest skonfigurowany. Sprawdź ustawienia w Admin → Moduły → Core → AI.');
        }

        try {
            $content = trim($ai->chat($context, self::PROMPT, [
                'temperature' => 0.4,
                // 4096 — zostawia miejsce na reasoning chain-of-thought w modelach typu Gemma 3 thinking.
                // Gemini/OpenAI bez thinking i tak nie wykorzystają tyle (zwracają tylko właściwy tekst).
                'max_tokens' => 4096,
            ]));
        } catch (AiClientException $e) {
            throw new \RuntimeException('AI: ' . $e->getMessage(), 0, $e);
        }

        return $content !== '' ? $content : "Przypomnij się, zapytaj o potrzeby i zaproponuj spotkanie lub ofertę.";
    }

    private function buildContext(Client $client): string
    {
        $parts = [];

        $parts[] = "Klient: {$client->name}";
        if ($client->contact_person) {
            $parts[] = "Osoba kontaktowa: {$client->contact_person}";
        }
        if ($client->notes) {
            $parts[] = "Notatki: {$client->notes}";
        }

        $profile = $client->profile;
        if (is_array($profile)) {
            $profileStr = $this->formatProfile($profile);
            if ($profileStr) {
                $parts[] = "Profil lokalu: {$profileStr}";
            }
        }

        $lastVisit = $this->getLastVisit($client);
        if ($lastVisit) {
            $visitStr = "Ostatnia wizyta: {$lastVisit->visit_date->format('d.m.Y')}";
            if ($lastVisit->description || $lastVisit->notes) {
                $visitStr .= "\n" . trim(($lastVisit->description ?? '') . "\n" . ($lastVisit->notes ?? ''));
            }
            $parts[] = $visitStr;
        }

        $lastCall = $this->getLastCallSummary($client);
        if ($lastCall) {
            $parts[] = "Ostatnia rozmowa: {$lastCall}";
        }

        return implode("\n\n", $parts);
    }

    private function formatProfile(array $profile): string
    {
        $items = [];
        foreach (['venue', 'concept', 'organization', 'potential'] as $section) {
            if (!isset($profile[$section]) || !is_array($profile[$section])) {
                continue;
            }
            foreach ($profile[$section] as $key => $val) {
                if ($val === null || $val === '' || $val === [] || $val === false) {
                    continue;
                }
                if (is_array($val)) {
                    $val = implode(', ', $val);
                }
                $items[] = "{$key}: {$val}";
            }
        }
        return implode('; ', array_slice($items, 0, 8));
    }

    private function getLastVisit(Client $client): ?ClientVisit
    {
        return ClientVisit::where('client_id', $client->id)
            ->orderByDesc('visit_date')
            ->first();
    }

    private function getLastCallSummary(Client $client): ?string
    {
        if (!Schema::hasTable('ringostat_calls') || !class_exists(\Modules\Ringostat\Models\RingostatCall::class)) {
            return null;
        }

        $call = \Modules\Ringostat\Models\RingostatCall::where('client_id', $client->id)
            ->where('disposition', 'ANSWERED')
            ->orderByDesc('call_date')
            ->first();

        if (!$call) {
            return null;
        }

        $date = $call->call_date->format('d.m.Y');
        if (!empty($call->ai_summary)) {
            return "{$date} – {$call->ai_summary}";
        }
        return "{$date} (brak transkrypcji)";
    }
}
