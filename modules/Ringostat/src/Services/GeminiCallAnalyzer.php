<?php

namespace Modules\Ringostat\Services;

use App\Services\AI\AiClientException;
use App\Services\AI\AiClientFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Ringostat\Models\RingostatCall;

/**
 * Analiza rozmów Play Centrali z użyciem AiClient (Gemini lub OpenAI-compatible).
 * Flow:
 *   1. Pobierz nagranie (WAV/MP3) z Play API.
 *   2. Transkrypcja: $ai->transcribe() — Gemini natywnie / Whisper przez OpenAI-compat.
 *   3. Speaker labeling: $ai->chat() oznacza "Pracownik:" / "Klient:".
 *   4. Analiza: $ai->chatJson() generuje pełną analizę handlową.
 *
 * Mimo nazwy klasy "Gemini" — od refactoru obsługuje dowolny provider z Settings.
 */
class GeminiCallAnalyzer
{
    public function __construct(private AiClientFactory $aiFactory) {}

    public function analyze(RingostatCall $call): RingostatCall
    {
        $recordingUrl = $call->recording_wav_url ?: $call->recording_url;
        if (empty($recordingUrl)) {
            throw new \RuntimeException('Brak nagrania do analizy');
        }

        $ai = $this->aiFactory->make();
        $audioAi = $this->aiFactory->makeForAudio();
        if (!$ai->isReady()) {
            throw new \RuntimeException('AI provider (tekst) nie jest skonfigurowany. Sprawdź Admin → Moduły → Core → AI.');
        }
        if (!$audioAi->isReady()) {
            throw new \RuntimeException('AI provider (audio) nie jest skonfigurowany. Ustaw ai_audio_provider w Admin → Moduły → Core → AI.');
        }

        $tempPath = $this->downloadRecording($recordingUrl);

        try {
            // 1. Transkrypcja audio → tekst (osobny provider — Gemini lub LM Studio whisper)
            try {
                $rawTranscript = trim($audioAi->transcribe($tempPath, 'pl', ['max_tokens' => 8192]));
            } catch (AiClientException $e) {
                throw new \RuntimeException('Transkrypcja audio (provider: ' . $audioAi->getProviderName() . '): ' . $e->getMessage(), 0, $e);
            }

            if (empty($rawTranscript)) {
                throw new \RuntimeException('Transkrypcja pusta');
            }

            // 2. Speaker labeling — oddzielny prompt
            $callContext = $this->buildCallContext($call);
            $transcript = $this->labelSpeakers($ai, $rawTranscript, $callContext);

            // 3. Analiza pełna (JSON)
            $hasClient = $call->client_id && $call->client;
            $analysis = $this->analyzeTranscript($ai, $transcript, $hasClient);

            $aiAnalysis = [
                'scores' => $analysis['scores'] ?? null,
                'typical_errors' => $analysis['typical_errors'] ?? null,
                'advanced_sale' => $analysis['advanced_sale'] ?? null,
                'crm_notes' => $analysis['crm_notes'] ?? null,
                'key_moments' => $analysis['key_moments'] ?? [],
                'keywords' => $analysis['keywords'] ?? [],
                'errors' => $analysis['errors'] ?? [],
                'good_aspects' => $analysis['good_aspects'] ?? [],
                'improvements' => $analysis['improvements'] ?? [],
                'next_steps' => $analysis['next_steps'] ?? [],
            ];

            $updateData = [
                'ai_transcript' => $transcript,
                'ai_summary' => $analysis['summary'] ?? null,
                'ai_customer_mood' => $analysis['customer_mood'] ?? null,
                'ai_employee_mood' => $analysis['employee_mood'] ?? null,
                'ai_overall_mood' => $analysis['overall_mood'] ?? null,
                'ai_recommendations' => $analysis['recommendations'] ?? null,
                'ai_analysis' => $aiAnalysis,
                'ai_analyzed_at' => now(),
                'ai_provider' => $ai->getProviderName(),
                'ai_model' => $ai->getModel(),
            ];

            if (isset($analysis['profile_suggestions'])) {
                $updateData['ai_profile_suggestions'] = $analysis['profile_suggestions'];
            }

            $call->update($updateData);
            $call->refresh();

            return $call;
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function downloadRecording(string $url): string
    {
        // Play API (uslugidlafirm.play.pl) — nagrania zaszyfrowane CMS, wymagają JWT + deszyfrowania
        if (str_contains($url, 'uslugidlafirm.play.pl')) {
            $service = app(RingostatService::class);
            $tempPath = $service->downloadAndDecryptRecording($url);

            if (!$tempPath) {
                throw new \RuntimeException('Nie udało się pobrać lub odszyfrować nagrania z Play API');
            }

            $size = filesize($tempPath);
            if ($size > 24 * 1024 * 1024) {
                $tempPath = $this->convertToMp3($tempPath);
            }

            return $tempPath;
        }

        // Inne źródła — plain HTTP
        $response = Http::timeout(120)->get($url);
        if (!$response->successful()) {
            throw new \RuntimeException("Nie udało się pobrać nagrania: HTTP {$response->status()}");
        }

        $ext = str_contains($url, '.mp3') ? '.mp3' : '.wav';
        $tempPath = tempnam(sys_get_temp_dir(), 'recording_') . $ext;
        file_put_contents($tempPath, $response->body());

        $size = filesize($tempPath);
        if ($size < 1000) {
            @unlink($tempPath);
            throw new \RuntimeException('Plik nagrania jest zbyt mały (' . $size . ' bytes)');
        }
        if ($size > 24 * 1024 * 1024) {
            $tempPath = $this->convertToMp3($tempPath);
        }

        return $tempPath;
    }

    /**
     * Konwertuj plik audio do MP3 za pomocą ffmpeg (WAV ~120MB → MP3 ~5MB).
     */
    private function convertToMp3(string $inputPath): string
    {
        $outputPath = preg_replace('/\.\w+$/', '', $inputPath) . '_compressed.mp3';

        $cmd = sprintf(
            'ffmpeg -i %s -codec:a libmp3lame -b:a 64k -ac 1 -ar 16000 -y %s 2>&1',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $exitCode);
        @unlink($inputPath);

        if ($exitCode !== 0 || !file_exists($outputPath)) {
            throw new \RuntimeException('Konwersja audio nie powiodła się (ffmpeg exit: ' . $exitCode . ')');
        }

        if (filesize($outputPath) > 25 * 1024 * 1024) {
            @unlink($outputPath);
            throw new \RuntimeException('Nagranie po kompresji nadal przekracza 25 MB');
        }

        return $outputPath;
    }

    private function buildCallContext(RingostatCall $call): string
    {
        $ctx = '';
        $direction = $call->call_type === 'incoming' ? 'Przychodząca (klient dzwoni)' : 'Wychodząca (pracownik dzwoni)';
        $ctx .= "Kierunek rozmowy: {$direction}\n";
        if ($call->employee_name) $ctx .= "Pracownik Chicken King: {$call->employee_name}\n";
        if ($call->client && $call->client->name) $ctx .= "Klient/Firma: {$call->client->name}\n";
        if ($call->caller) $ctx .= "Numer dzwoniącego: {$call->caller}\n";
        if ($call->destination) $ctx .= "Numer odbierającego: {$call->destination}\n";
        return $ctx;
    }

    /**
     * Oznacza mówców w transkrypcji ("Pracownik:" / "Klient:") drugim promptem.
     * Bez tego dla LM Studio + Whisper dostajemy tylko płaski tekst.
     */
    private function labelSpeakers(\App\Services\AI\AiClient $ai, string $transcript, string $callContext): string
    {
        $systemPrompt = 'Jesteś asystentem przetwarzającym transkrypcje rozmów telefonicznych B2B. Twoja praca polega WYŁĄCZNIE na oznaczeniu mówców prefiksami i zwróceniu poprawionej transkrypcji.';

        $prompt = <<<PROMPT
KONTEKST ROZMOWY:
{$callContext}

ZADANIE: Oznacz mówców w poniższej transkrypcji prefiksami "Pracownik:" lub "Klient:" — każda zmiana mówcy = nowa linia.

ZASADY:
- "Pracownik:" — osoba z firmy Chicken King (dostawca panierki, sprzedawca B2B)
- "Klient:" — restaurator, właściciel lokalu, osoba zamawiająca
- Jeśli rozmowa WYCHODZĄCA → pracownik mówi pierwszy
- Jeśli PRZYCHODZĄCA → klient mówi pierwszy
- Osoba mówiąca "nasze punkty / nasz lokal" → KLIENT
- Osoba oferująca produkty / znająca ceny / opisująca panierkę → PRACOWNIK
- Zachowaj CAŁY tekst, niczego nie pomijaj
- Odpowiedz TYLKO oznaczoną transkrypcją (bez komentarzy)

TRANSKRYPCJA DO OZNACZENIA:
{$transcript}
PROMPT;

        try {
            return trim($ai->chat($prompt, $systemPrompt, [
                'temperature' => 0.1,
                'max_tokens' => 8192,
            ]));
        } catch (AiClientException $e) {
            // Jeśli labeling padnie, lepsza pełna transkrypcja bez labelek niż brak danych
            Log::warning('Speaker labeling failed, fallback to raw transcript', ['error' => $e->getMessage()]);
            return $transcript;
        }
    }

    /**
     * Wczytuje listę dodatkowych zasad z memory.md (Admin → Uczenie AI).
     */
    private function loadMemory(): string
    {
        $path = storage_path('app/ai_memory/ringostat_analysis.md');
        if (!file_exists($path)) return '';
        $content = file_get_contents($path) ?: '';
        if ($content === '') return '';

        if (!preg_match('/## Dodatkowe zasady analizy\s*(.*)$/s', $content, $m)) {
            return '';
        }

        $rules = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            if (preg_match('/^-\s+(.+)$/', $line, $bm)) {
                $rules[] = '- ' . trim($bm[1]);
            }
        }

        return empty($rules) ? '' : implode("\n", $rules);
    }

    private function analyzeTranscript(\App\Services\AI\AiClient $ai, string $transcript, bool $extractProfile = false): array
    {
        $profileBlock = '';
        if ($extractProfile) {
            $profileBlock = ',
  "profile_suggestions": {
    "venue_type": "stacjonarny/kontener/food_truck/przyczepa lub null",
    "venue_size": "opis lub null", "serves_chicken": "bool lub null",
    "decision_maker": "wlasciciel/menedzer/kucharz lub null",
    "open_to_tests": "bool lub null"
  }';
        }

        $basePrompt = <<<'SYS'
Jesteś doświadczonym trenerem sprzedaży firmy Chicken King Family — dostawcy panierki do chrupiącego kurczaka dla gastronomii B2B (fast food, kebab, burgerownie). Oceń rozmowę pod kątem SKUTECZNOŚCI SPRZEDAŻOWEJ. Bądź surowy ale sprawiedliwy.

DOBRA rozmowa: handlowiec PYTA więcej niż mówi, diagnozuje klienta, prezentuje ofertę DOPIERO po zrozumieniu sytuacji, kończy z konkretnym krokiem.
ZŁA rozmowa: monolog o produkcie, brak pytań, brak diagnozy, brak ustaleń na końcu.
SYS;
        $memory = $this->loadMemory();
        $systemPrompt = $memory
            ? $basePrompt . "\n\n## DODATKOWE INSTRUKCJE (pamięć AI)\n" . $memory
            : $basePrompt;

        $prompt = <<<PROMPT
Przeanalizuj rozmowę. Odpowiedz WYŁĄCZNIE poprawnym JSON-em:
{
  "summary": "3-5 zdań: kto dzwonił, w jakiej sprawie, co ustalono",
  "customer_mood": "pozytywny/neutralny/negatywny/zainteresowany/sceptyczny",
  "employee_mood": "profesjonalny/pomocny/nachalny/chaotyczny/zaangażowany",
  "overall_mood": "1-2 zdania o dynamice rozmowy",
  "recommendations": "Najważniejsza rada dla handlowca (2-3 zdania)",
  "scores": {
    "opening": "1-10", "diagnosis": "1-10", "questions": "1-10",
    "listening": "1-10", "leading": "1-10", "presentation": "1-10",
    "guiding": "1-10", "next_step": "1-10", "style": "1-10", "overall": "1.0-10.0"
  },
  "typical_errors": {
    "monologue": "0-3", "assumptions": "0-3", "premature_pitch": "0-3",
    "no_deepening": "0-3", "chaos": "0-3", "over_convincing": "0-3", "no_next_step": "0-3",
    "biggest_problem": "jedno zdanie z cytatem",
    "one_thing_to_improve": "jedno zdanie"
  },
  "advanced_sale": "TAK/CZĘŚCIOWO/NIE",
  "crm_notes": "3-6 punktów do CRM",
  "key_moments": ["cytat + dlaczego ważny (max 5-7)"],
  "keywords": ["max 10-12 słów"],
  "errors": ["CYTAT → co źle (max 5)"],
  "good_aspects": ["co dobrze (max 5)"],
  "improvements": ["Zamiast: CYTAT → Lepiej: alternatywa (max 5)"],
  "next_steps": ["krok po rozmowie (max 3-5)"]{$profileBlock}
}

TRANSKRYPCJA:
{$transcript}
PROMPT;

        try {
            return $ai->chatJson($prompt, $systemPrompt, [
                'temperature' => 0.3,
                'max_tokens' => 8192,
            ]);
        } catch (AiClientException $e) {
            Log::warning('Call analysis JSON parse failed', ['error' => $e->getMessage()]);
            return ['summary' => 'Analiza AI nieudana — ' . $e->getMessage()];
        }
    }
}
