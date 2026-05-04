<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiTrainingMessage;
use App\Services\AI\AiClientException;
use App\Services\AI\AiClientFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class AiTrainingController extends Controller
{
    private string $memoryPath;
    private string $metaPath;

    private const HEADER = "# Pamięć AI — analiza rozmów Play Centrala\n\n> Lista dodatkowych zasad dołączanych do każdej analizy rozmowy.\n> Modyfikujesz przez czat w Admin → Uczenie AI. AI może TYLKO dodać nową zasadę lub usunąć wskazaną — nie przepisuje całości.\n\n## Dodatkowe zasady analizy\n";
    private const EMPTY_MARKER = "*(brak dodatkowych zasad — model używa tylko domyślnych ustawień)*\n";

    public function __construct(private AiClientFactory $aiFactory)
    {
        $this->memoryPath = storage_path('app/ai_memory/ringostat_analysis.md');
        $this->metaPath   = storage_path('app/ai_memory/ringostat_analysis.meta.json');
    }

    public function index(): Response
    {
        return Inertia::render('Admin/AiTraining/Index', [
            'memory'   => $this->readMemory(),
            'meta'     => $this->readMeta(),
            'messages' => $this->loadMessagesForClient(),
        ]);
    }

    /**
     * Zwraca ostatnie N wiadomości w formacie dla frontendu (współdzielona historia zespołu).
     */
    public function messages(Request $request): JsonResponse
    {
        return response()->json([
            'messages' => $this->loadMessagesForClient(),
        ]);
    }

    public function clearMessages(Request $request): JsonResponse
    {
        if (!Schema::hasTable('ai_training_messages')) {
            return response()->json(['success' => true, 'messages' => []]);
        }
        AiTrainingMessage::truncate();
        return response()->json(['success' => true, 'messages' => []]);
    }

    private function loadMessagesForClient(int $limit = 200): array
    {
        if (!Schema::hasTable('ai_training_messages')) return [];

        return AiTrainingMessage::with('user:id,name')
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get()
            ->map(fn ($m) => [
                'id'         => $m->id,
                'role'       => $m->role,
                'content'    => $m->content,
                'meta'       => $m->meta,
                'user_name'  => $m->user?->name,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->toArray();
    }

    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'content' => 'required|string|max:10000',
        ]);

        $ai = $this->aiFactory->make();
        if (!$ai->isReady()) {
            return response()->json(['error' => 'AI provider nie jest skonfigurowany. Sprawdź ustawienia w Admin → Moduły → Core → AI.'], 500);
        }

        // Zapisz wiadomość użytkownika w DB (od razu — inni userzy widzą ją przez polling)
        AiTrainingMessage::create([
            'user_id' => auth()->id(),
            'role'    => 'user',
            'content' => $request->input('content'),
        ]);

        $currentRules = $this->readRules();
        $rulesText = empty($currentRules)
            ? '(brak zasad dodatkowych)'
            : implode("\n", array_map(fn ($i, $r) => ($i + 1) . '. ' . $r, array_keys($currentRules), $currentRules));

        $systemPrompt = <<<SYSTEM
Jesteś asystentem który pomaga administratorowi konfigurować AI do analizy rozmów handlowych Chicken King CRM.

## Co TY możesz zrobić
Możesz tylko ZARZĄDZAĆ LISTĄ DODATKOWYCH ZASAD którą AI bierze pod uwagę przy analizie rozmów:
- DODAĆ nową zasadę (jednolinijkowy bullet-point, zwięzły, max 200 znaków)
- USUNĄĆ istniejącą zasadę po numerze
- LUB wyłącznie porozmawiać — nie wszystko wymaga zmiany pliku

NIE MOŻESZ zmieniać struktury, nagłówków, ani domyślnego promptu analizy. Twoje zmiany trafiają TYLKO do tej listy.

## Aktualne zasady dodatkowe
{$rulesText}

## Jak zwracać zmiany
Jeśli chcesz DODAĆ zasadę — na końcu swojej odpowiedzi wstaw:
---ADD_RULE---
treść nowej zasady (jedna linia)
---END_RULE---

Jeśli chcesz USUNĄĆ zasadę — wstaw (gdzie N to numer z listy powyżej):
---REMOVE_RULE---
N
---END_RULE---

Możesz wstawić wiele bloków jeśli potrzebujesz wiele zmian.

## Zasady pisania zasad
- Zwięźle, konkretnie, w jednej linii
- Opisuj zasadę jak instrukcję dla AI analizującego rozmowę, np. "Jeśli klient wspomina konkurencję, oceń diagnozę jako słabą gdy handlowiec nie dopytał" lub "Uwzględnij że sprzedajemy głównie B2B gastronomii, nie indywidualnym klientom"
- NIE wklejaj całych regułek analizy — AI ma już swój domyślny prompt, to tylko wyjątki/kontekst

Odpowiadaj po polsku.
SYSTEM;

        // Buduj historię konwersacji z DB (limit ostatnich 30 — żeby nie przesyłać za dużo)
        $history = AiTrainingMessage::orderBy('id', 'desc')->limit(30)->get()->reverse()->values();
        $messages = [];
        foreach ($history as $msg) {
            $messages[] = [
                'role'    => $msg->role === 'assistant' ? 'assistant' : 'user',
                'content' => $msg->content,
            ];
        }

        try {
            $content = $ai->chatMessages($messages, [
                'system_prompt' => $systemPrompt,
                'temperature' => 0.4,
                'max_tokens' => 4096,
            ]);

            $changes = $this->applyRuleChanges($content, $currentRules);

            // Oczyść response z bloków ADD/REMOVE
            $content = trim(preg_replace('/---(?:ADD_RULE|REMOVE_RULE)---[\s\S]*?---END_RULE---/m', '', $content));

            // Zapisz odpowiedź assistanta w DB — każdy user widzi ją przez polling
            AiTrainingMessage::create([
                'user_id' => null,
                'role'    => 'assistant',
                'content' => $content,
                'meta'    => $changes['updated'] ? [
                    'added'   => $changes['added'],
                    'removed' => $changes['removed'],
                ] : null,
            ]);

            Log::info('AI Training chat', ['changes' => $changes, 'user_id' => auth()->id()]);

            return response()->json([
                'message'        => $content,
                'memory_updated' => $changes['updated'],
                'memory'         => $changes['updated'] ? $this->readMemory() : null,
                'meta'           => $changes['updated'] ? $this->readMeta() : null,
                'added'          => $changes['added'],
                'removed'        => $changes['removed'],
                'messages'       => $this->loadMessagesForClient(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AI Training chat error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Błąd API: ' . $e->getMessage()], 500);
        }
    }

    public function getMemory(): JsonResponse
    {
        return response()->json([
            'memory' => $this->readMemory(),
            'meta'   => $this->readMeta(),
        ]);
    }

    public function resetMemory(): JsonResponse
    {
        $this->writeRules([]);
        return response()->json([
            'memory' => $this->readMemory(),
            'meta'   => $this->readMeta(),
        ]);
    }

    /**
     * Zastosuj ADD_RULE / REMOVE_RULE z odpowiedzi Gemini do listy zasad.
     */
    private function applyRuleChanges(string $content, array $currentRules): array
    {
        $added = [];
        $removed = [];

        // ADD_RULE blocks
        if (preg_match_all('/---ADD_RULE---\s*(.*?)\s*---END_RULE---/s', $content, $addMatches)) {
            foreach ($addMatches[1] as $raw) {
                $rule = trim(preg_replace('/\s+/', ' ', $raw));
                $rule = ltrim($rule, '-* '); // usuń ewentualny prefiks bullet
                if (empty($rule) || mb_strlen($rule) > 400) continue;
                if (in_array($rule, $currentRules, true)) continue; // dedup
                $added[] = $rule;
            }
        }

        // REMOVE_RULE blocks (po numerze)
        if (preg_match_all('/---REMOVE_RULE---\s*(\d+)\s*---END_RULE---/', $content, $removeMatches)) {
            foreach ($removeMatches[1] as $num) {
                $idx = (int) $num - 1;
                if (isset($currentRules[$idx])) {
                    $removed[] = $currentRules[$idx];
                }
            }
        }

        if (empty($added) && empty($removed)) {
            return ['updated' => false, 'added' => [], 'removed' => []];
        }

        // Buduj nowy zestaw
        $newRules = $currentRules;
        foreach ($removed as $rm) {
            $newRules = array_values(array_filter($newRules, fn ($r) => $r !== $rm));
        }
        foreach ($added as $add) {
            $newRules[] = $add;
        }

        $this->writeRules($newRules);

        return ['updated' => true, 'added' => $added, 'removed' => $removed];
    }

    /**
     * Odczytaj zasady jako listę stringów (z pliku markdown — bullet points `- ...`).
     */
    private function readRules(): array
    {
        if (!file_exists($this->memoryPath)) return [];
        $content = file_get_contents($this->memoryPath) ?: '';

        // Sekcja "## Dodatkowe zasady analizy" — wszystkie linie zaczynające się od `- `
        if (!preg_match('/## Dodatkowe zasady analizy\s*(.*)$/s', $content, $m)) {
            return [];
        }

        $rules = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            if (preg_match('/^-\s+(.+)$/', $line, $bm)) {
                $rules[] = trim($bm[1]);
            }
        }
        return $rules;
    }

    private function writeRules(array $rules): void
    {
        $dir = dirname($this->memoryPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $body = empty($rules)
            ? self::EMPTY_MARKER
            : implode("\n", array_map(fn ($r) => '- ' . $r, $rules)) . "\n";

        file_put_contents($this->memoryPath, self::HEADER . $body);

        // Metadane ostatniej edycji (dla synchronizacji między userami)
        $meta = [
            'updated_at'   => now()->toIso8601String(),
            'updated_by'   => auth()->user()?->name ?? 'System',
            'rules_count'  => count($rules),
        ];
        file_put_contents($this->metaPath, json_encode($meta, JSON_PRETTY_PRINT));
    }

    private function readMemory(): string
    {
        if (!file_exists($this->memoryPath)) {
            // Pierwszy run — zainicjalizuj domyślną strukturą
            $this->writeRules([]);
        }
        return file_get_contents($this->memoryPath) ?: '';
    }

    private function readMeta(): array
    {
        if (!file_exists($this->metaPath)) {
            return ['updated_at' => null, 'updated_by' => null, 'rules_count' => 0];
        }
        return json_decode(file_get_contents($this->metaPath), true) ?: [];
    }
}
