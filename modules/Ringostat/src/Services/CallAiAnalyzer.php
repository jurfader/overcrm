<?php

namespace Modules\Ringostat\Services;

use App\Models\Client;
use App\Services\AI\AiClient;
use App\Services\AI\AiClientException;
use App\Services\AI\AiClientFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Ringostat\Models\RingostatCall;

class CallAiAnalyzer
{
    public function __construct(private AiClientFactory $aiFactory) {}

    /**
     * Analyze a call: download recording, transcribe, summarize.
     * Używa AiClient (Gemini lub OpenAI-compatible) wybranego przez Settings.
     * Returns the updated call or throws on failure.
     */
    public function analyze(RingostatCall $call): RingostatCall
    {
        $ai = $this->aiFactory->make();
        $audioAi = $this->aiFactory->makeForAudio();
        if (!$ai->isReady()) {
            throw new \RuntimeException('AI provider (tekst) nie jest skonfigurowany. Sprawdź Admin → Moduły → Core → AI.');
        }
        if (!$audioAi->isReady()) {
            throw new \RuntimeException('AI provider (audio) nie jest skonfigurowany. Ustaw ai_audio_provider w Admin → Moduły → Core → AI.');
        }

        $recordingUrl = $call->recording_wav_url ?: $call->recording_url;

        if (empty($recordingUrl)) {
            throw new \RuntimeException('Brak nagrania do analizy');
        }

        $tempPath = $this->downloadRecording($recordingUrl);

        try {
            $transcript = $this->transcribe($ai, $audioAi, $tempPath, $call);

            if (empty(trim($transcript))) {
                throw new \RuntimeException('Transkrypcja pusta — nagranie może być zbyt krótkie lub niesłyszalne');
            }

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
            ];

            if ($hasClient && !empty($analysis['profile_suggestions'])) {
                $updateData['ai_profile_suggestions'] = $analysis['profile_suggestions'];
            }

            $call->update($updateData);

            Log::info('Call AI analysis completed', [
                'call_id' => $call->call_id,
                'has_profile_suggestions' => !empty($analysis['profile_suggestions']),
            ]);

            return $call->fresh();
        } finally {
            @unlink($tempPath);
        }
    }

    private function downloadRecording(string $url): string
    {
        // Play API (uslugidlafirm.play.pl) — nagrania są zaszyfrowane, wymagają JWT + deszyfrowania
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
            throw new \RuntimeException('Nie udało się pobrać nagrania: HTTP ' . $response->status());
        }

        $extension = str_contains($url, '.wav') ? 'wav' : 'mp3';
        $tempPath  = storage_path('app/temp_recording_' . uniqid() . '.' . $extension);

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
     * Konwertuj plik audio do MP3 za pomocą ffmpeg (WAV 120MB → MP3 ~5MB).
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

        $newSize = filesize($outputPath);
        Log::info('Audio converted to MP3', [
            'output_size' => round($newSize / 1024 / 1024, 2) . ' MB',
        ]);

        if ($newSize > 25 * 1024 * 1024) {
            @unlink($outputPath);
            throw new \RuntimeException('Nagranie po kompresji nadal przekracza 25 MB (' . round($newSize / 1024 / 1024, 1) . ' MB)');
        }

        return $outputPath;
    }

    private function transcribe(AiClient $ai, AiClient $audioAi, string $filePath, RingostatCall $call): string
    {
        try {
            $rawTranscript = $audioAi->transcribe($filePath, 'pl', ['max_tokens' => 8192]);
        } catch (AiClientException $e) {
            throw new \RuntimeException('Transkrypcja audio (' . $audioAi->getProviderName() . '): ' . $e->getMessage(), 0, $e);
        }

        return $this->addSpeakerLabels($ai, $rawTranscript, $call);
    }

    private function addSpeakerLabels(AiClient $ai, string $rawTranscript, RingostatCall $call): string
    {
        // Zbuduj kontekst rozmowy z metadanych
        $callContext = '';
        $direction = $call->call_type === 'incoming' ? 'Przychodząca (klient dzwoni)' : 'Wychodząca (pracownik dzwoni)';
        $callContext .= "Kierunek rozmowy: {$direction}\n";

        if ($call->employee_name) {
            $callContext .= "Pracownik Chicken King: {$call->employee_name}\n";
        }
        if ($call->client && $call->client->name) {
            $callContext .= "Klient/Firma: {$call->client->name}\n";
        }
        if ($call->caller) {
            $callContext .= "Numer dzwoniącego: {$call->caller}\n";
        }
        if ($call->destination) {
            $callContext .= "Numer odbierającego: {$call->destination}\n";
        }

        $systemPrompt = <<<'SYS'
Jesteś ekspertem od transkrypcji rozmów telefonicznych firmy Chicken King Family (dostawca panierki do chrupiącego kurczaka dla gastronomii B2B).

Twoim zadaniem jest podzielić surową transkrypcję na wypowiedzi poszczególnych osób i oznaczenie kto mówi.

## KLUCZOWA ZASADA IDENTYFIKACJI
Otrzymasz KONTEKST ROZMOWY z metadanymi — kierunek połączenia, imię pracownika, nazwę klienta. UŻYJ TYCH DANYCH:
- Jeśli rozmowa WYCHODZĄCA → pracownik Chicken King mówi PIERWSZY (on dzwoni)
- Jeśli rozmowa PRZYCHODZĄCA → klient mówi PIERWSZY (on dzwoni)
- Imię pracownika pomoże go zidentyfikować w rozmowie

## OZNACZANIE MÓWCÓW
- "Pracownik:" — osoba z Chicken King. Rozpoznajesz po: przedstawia się / oferuje produkty / zna szczegóły oferty / proponuje próbki
- "Klient:" — restaurator, właściciel lokalu. Rozpoznajesz po: pyta o ceny / opowiada o swoim lokalu / mówi o swoich potrzebach / zamawia

## KLUCZOWE WSKAZÓWKI
- Osoba która mówi "nasze punkty", "nasz lokal", "u nas" w kontekście restauracji/lokalu → to KLIENT
- Osoba która mówi "nasza panierka", "nasz produkt", "oferujemy" → to PRACOWNIK
- Osoba która pyta "ile kosztuje", "jaka cena", "jakie warunki" → to KLIENT
- Osoba która odpowiada na pytania o ceny i podaje szczegóły oferty → to PRACOWNIK

## FORMATOWANIE
- Każda zmiana mówcy = nowa linia z odpowiednim prefiksem
- Zachowaj CAŁY tekst — nic nie pomijaj, nic nie streszczaj, nic nie parafrazuj
- Zachowaj naturalne przerwy, "mhm", "aha", "tak tak" — to ważne dla analizy
- Jeśli ktoś mówi kilka zdań pod rząd bez przerwania, zachowaj jako jeden blok
- Odpowiedz TYLKO sformatowaną transkrypcją, bez komentarzy
SYS;

        try {
            return $ai->chat(
                "KONTEKST ROZMOWY:\n{$callContext}\nTRANSKRYPCJA DO PODZIELENIA:\n{$rawTranscript}",
                $systemPrompt,
                ['temperature' => 0.1, 'max_tokens' => 16000]
            );
        } catch (AiClientException $e) {
            Log::warning('Speaker labeling failed, fallback to raw transcript', ['error' => $e->getMessage()]);
            return $rawTranscript;
        }
    }

    private function loadMemory(): string
    {
        $path = storage_path('app/ai_memory/ringostat_analysis.md');
        if (!file_exists($path)) return '';
        $content = trim(file_get_contents($path) ?: '');
        // Nie dołączaj jeśli to tylko domyślna (pusta) pamięć
        if (str_contains($content, 'brak dodatkowych instrukcji')) return '';
        return $content;
    }

    private const SYSTEM_PROMPT = <<<'SYS'
Jesteś doświadczonym trenerem sprzedaży i analitykiem rozmów handlowych w firmie Chicken King Family Sp. z o.o.

## O FIRMIE
Chicken King Family to producent i dostawca profesjonalnej panierki do chrupiącego kurczaka (styl KFC), ryb i warzyw dla gastronomii B2B. Klienci to: fast foody, kebaby, burgerownie, lokale z kurczakiem, food trucki, kontenery gastronomiczne. Firma współpracuje z ponad 500 partnerami gastronomicznymi w Polsce.

## PRODUKTY I OFERTA
- Panierka do chrupiącego kurczaka (1.4 kg, 4.8 kg, 9 kg, 3-pack 27 kg, 10+1 pack 99 kg)
- Panierka do ryb i Panierka Vege (analogiczne rozmiary)
- Pakiet Startowy (215 zł) — panierka 9kg + marynaty + próbki opakowań + ulotki
- Marynaty (pikantna, łagodna), sosy, opakowania brandowane "Dobra Szama"
- Dobra Szama — sublinia: boxy, kubełki, tortille, torby papierowe, rożki
- Frytury profesjonalne
- KORZYŚCI: marża do 70%, oszczędność 30% na mięsie, chrupiący kurczak w 3 minuty, darmowa próbka, wsparcie marketingowe, darmowa dostawa od 150 zł

## FILOZOFIA OCENY — BĄDŹ WYMAGAJĄCY
Oceniasz skuteczność BIZNESOWĄ, nie uprzejmość. Jesteś surowym ale sprawiedliwym trenerem.

### DOBRA rozmowa (7-10 pkt):
- Handlowiec PYTA więcej niż mówi (proporcja 40/60 na korzyść klienta)
- Szybko rozumie kontekst klienta i jego lokal
- Zadaje otwarte pytania pogłębiające ("A ile kurczaków dziennie pan smaży?", "Jakie macie obroty na kurczaku?")
- Słucha odpowiedzi i reaguje na nie (nie ignoruje tego co klient mówi)
- Prezentuje ofertę DOPIERO po zrozumieniu sytuacji (po 3-5 pytaniach minimum)
- Naprowadza klienta na wnioski pytaniami ("Gdyby pan mógł podnieść marżę o 20% na kurczaku, to by się opłacało?")
- Kończy z KONKRETNYM następnym krokiem (data, godzina, co się wydarzy)
- Zbiera dane do CRM (rodzaj lokalu, obroty, current suppliers)

### ZŁA rozmowa (1-4 pkt):
- Handlowiec mówi 80% czasu — monolog o produkcie
- Zaczyna od "Mamy super panierkę..." zanim zapyta czym klient się zajmuje
- Zakłada potrzeby klienta ("Na pewno panu się spodoba")
- Klient mówi coś ważnego a handlowiec zmienia temat
- Nie dopytuje o szczegóły ("Mamy lokal" → brak pytania jaki, gdzie, ile osób)
- Kończy "to ja wyślę ofertę" bez ustalenia follow-up
- Brak zbierania informacji o biznesie klienta

### ŚREDNIA rozmowa (5-6 pkt):
- Mix dobrych i złych elementów
- Handlowiec próbuje pytać ale nie pogłębia
- Rozmowa idzie w dobrym kierunku ale brak konkretu na końcu

NIE dawaj wysokich ocen za:
- Samą uprzejmość bez skuteczności
- Długie rozmowy gdzie handlowiec dużo mówi (to wada, nie zaleta)
- "Profesjonalne brzmienie" bez realnej diagnozy potrzeb

Odpowiadasz WYŁĄCZNIE poprawnym JSON-em, bez bloków markdown ani dodatkowego tekstu.
SYS;

    private function buildSystemPrompt(): string
    {
        $base = self::SYSTEM_PROMPT;
        $memory = $this->loadMemory();
        if ($memory === '') return $base;
        return $base . "\n\n## DODATKOWE INSTRUKCJE (pamięć AI)\n" . $memory;
    }

    private function analyzeTranscript(AiClient $ai, string $transcript, bool $extractProfile = false): array
    {
        $profileBlock = '';
        if ($extractProfile) {
            $profileBlock = <<<'PROFILE'
,
  "profile_suggestions": {
    "// INSTRUKCJA": "Obiekt z TYLKO tymi kluczami, o których klient wspomniał. Jeśli nic nie padło — null.",
    "venue_type": "stacjonarny/kontener/food_truck/przyczepa",
    "venue_size": "opis", "kitchen_staff": "int", "total_staff": "int",
    "years_in_business": "int", "specialty": "tekst", "cuisine": "tekst",
    "price_level": "niski/średni/wysoki/premium",
    "delivery": "bool", "delivery_volume": "tekst",
    "platforms": ["pyszne","glovo","uber_eats","wolt"],
    "rush_hours": "tekst", "customer_profiles": ["rodziny","studenci"],
    "serves_chicken": "bool", "serving_form": "tekst", "chicken_volume": "tekst",
    "own_production": "bool", "uses_semi_finished": "bool", "suppliers": "tekst",
    "decision_maker": "wlasciciel/menedzer/kucharz",
    "ordering_person": "tekst", "ordering_frequency": "tekst",
    "personality": ["konkretny","szybki"],
    "promo_activities": "tekst", "current_products": "tekst",
    "menu_changes": "bool", "open_to_tests": "bool"
  }
PROFILE;
        }

        $prompt = <<<PROMPT
Przeanalizuj poniższą rozmowę handlową. Oceń ją pod kątem SKUTECZNOŚCI SPRZEDAŻOWEJ — bądź surowy ale sprawiedliwy jak doświadczony trener sprzedaży.

## CO OCENIASZ
1. Czy handlowiec NAJPIERW diagnozował sytuację klienta (pytania), a DOPIERO POTEM prezentował ofertę?
2. Jaka była proporcja mówienia handlowca vs klienta? (idealnie 40/60)
3. Czy handlowiec pogłębiał odpowiedzi klienta (follow-up questions)?
4. Czy rozmowa zakończyła się KONKRETNYM następnym krokiem?
5. Jakie informacje handlowiec zebrał o biznesie klienta?

## WAŻNE
- Jeśli handlowiec mówił większość czasu → to jest WADA, nie zaleta
- Jeśli handlowiec zaczął od opowiadania o produkcie zanim zapytał o lokal → niskie oceny za diagnosis i questions
- Jeśli rozmowa zakończyła się "to ja wyślę ofertę" bez daty/godziny → niskie next_step
- "Konkretne przykłady" w errors/improvements muszą odwoływać się do DOKŁADNYCH fragmentów rozmowy (cytaty)

Odpowiedz WYŁĄCZNIE poprawnym JSON-em (bez bloków markdown):
{
  "summary": "Podsumowanie rozmowy (3-5 zdań): kto dzwonił, w jakiej sprawie, co ustalono, jaki był następny krok, czy rozmowa przybliżyła sprzedaż",
  "customer_mood": "pozytywny/neutralny/negatywny/zniecierpliwiony/zadowolony/zainteresowany/sceptyczny/zdenerwowany",
  "employee_mood": "profesjonalny/pomocny/obojętny/pewny_siebie/nieprzygotowany/zaangażowany/nachalny/chaotyczny",
  "overall_mood": "1-2 zdania o dynamice rozmowy, kto dominował i jak to wpłynęło na efekt",
  "recommendations": "Najważniejsza rada dla handlowca (2-3 zdania) — co powinien zmienić w NASTĘPNEJ rozmowie",
  "scores": {
    "opening": "1-10 int — Otwarcie: przedstawienie się, jasny cel, nawiązanie kontaktu. 1-3: brak przedstawienia/celu, 4-6: standardowe ale bez haka, 7-10: celne otwarcie budzące ciekawość",
    "diagnosis": "1-10 int — Diagnoza klienta: zrozumienie lokalu, menu, obrotów, potrzeb. 1-3: zero pytań o klienta, 4-6: ogólne pytania, 7-10: głęboka diagnoza z konkretnymi liczbami",
    "questions": "1-10 int — Jakość pytań: otwarte, pogłębiające, budujące obraz. 1-3: brak pytań lub zamknięte, 4-6: są pytania ale powierzchowne, 7-10: trafne otwarte pytania z follow-up",
    "listening": "1-10 int — Słuchanie: reagowanie na odpowiedzi, dopytywanie, odnoszenie się. 1-3: ignoruje odpowiedzi klienta, 4-6: słucha ale nie pogłębia, 7-10: aktywne słuchanie z pogłębianiem",
    "leading": "1-10 int — Prowadzenie: jasny kierunek rozmowy, logiczny przepływ. 1-3: chaotyczne skakanie, 4-6: jest struktura ale luźna, 7-10: jasna metodologia krok po kroku",
    "presentation": "1-10 int — Prezentacja oferty: powiązanie z sytuacją klienta. 1-3: monolog o produkcie, 4-6: opowiada o produkcie z odniesieniami, 7-10: oferta wynika z diagnozy, klient sam widzi wartość",
    "guiding": "1-10 int — Naprowadzanie: pytania prowadzące do wniosków vs przekonywanie. 1-3: 'musisz to kupić', 4-6: argumenty ale wprost, 7-10: klient sam dochodzi do wniosków",
    "next_step": "1-10 int — Następny krok: konkretne ustalenie. 1-3: brak ustaleń, 4-6: ogólne 'odezwę się', 7-10: konkretna data+godzina+co się wydarzy",
    "style": "1-10 int — Styl: dialog vs monolog, partnerski vs nachalny. 1-3: monolog/nachalny, 4-6: mix, 7-10: naturalny dialog, partnerskie podejście",
    "overall": "1.0-10.0 float — Średnia ważona: diagnosis i questions mają wagę x2, next_step x1.5"
  },
  "typical_errors": {
    "monologue": "0-3 int — 0: dialog, 1: lekki monolog, 2: wyraźny monolog, 3: handlowiec mówi 80%+ czasu",
    "assumptions": "0-3 int — 0: pyta zamiast zakładać, 1-2: pojedyncze założenia, 3: ciągle zakłada",
    "premature_pitch": "0-3 int — 0: najpierw diagnoza, 1: lekki pitch za wcześnie, 2-3: od razu zaczyna od oferty",
    "no_deepening": "0-3 int — 0: pogłębia świetnie, 1-2: pomija niektóre wątki, 3: nigdy nie dopytuje",
    "chaos": "0-3 int — 0: logiczna struktura, 1-2: lekki chaos, 3: kompletny chaos",
    "over_convincing": "0-3 int — 0: prowadzi pytaniami, 1-2: mieszany styl, 3: ciągłe przekonywanie",
    "no_next_step": "0-3 int — 0: konkretny krok, 1: ogólny krok, 2: 'odezwę się', 3: brak ustaleń",
    "biggest_problem": "Jedno zdanie z CYTATEM z rozmowy — największy problem tej rozmowy",
    "one_thing_to_improve": "Jedno zdanie — gdyby handlowiec mógł zmienić JEDNĄ rzecz, to co?"
  },
  "advanced_sale": "TAK/CZĘŚCIOWO/NIE — czy ta rozmowa realnie przybliżyła sprzedaż (ustalono spotkanie/próbkę/zamówienie = TAK)",
  "crm_notes": "Jakie informacje należy zapisać do CRM po tej rozmowie (3-6 konkretnych punktów z danymi)",
  "key_moments": ["Cytat + dlaczego jest ważny (moment 1)", "Cytat + dlaczego (moment 2)", "max 5-7 momentów"],
  "keywords": ["słowo1", "słowo2", "max 10-12 słów kluczowych z rozmowy"],
  "errors": ["CYTAT z rozmowy → co było źle i dlaczego (max 5, bądź konkretny — odwołuj się do fragmentów)"],
  "good_aspects": ["CYTAT lub opis → co handlowiec zrobił dobrze i dlaczego to działa (max 5)"],
  "improvements": ["Zamiast: 'CYTAT handlowca' → Lepiej: 'konkretna alternatywa' (max 5 przykładów z rozmowy)"],
  "next_steps": ["Konkretny krok 1 po rozmowie z terminem", "Krok 2 (max 3-5)"]$profileBlock
}

TRANSKRYPCJA:
$transcript
PROMPT;

        try {
            $parsed = $ai->chatJson($prompt, $this->buildSystemPrompt(), [
                'temperature' => 0.3,
                'max_tokens' => $extractProfile ? 8192 : 8192,
            ]);
        } catch (AiClientException $e) {
            Log::warning('AI analysis failed', ['error' => $e->getMessage()]);
            return [
                'summary' => 'Analiza AI nieudana — ' . $e->getMessage(),
                'customer_mood' => null,
                'employee_mood' => null,
                'overall_mood' => null,
                'recommendations' => null,
                'profile_suggestions' => null,
            ];
        }

        return $parsed;
    }
}
