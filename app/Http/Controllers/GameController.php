<?php

namespace App\Http\Controllers;

use App\Models\GameScore;
use App\Models\GameSetting;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class GameController extends Controller
{
    public function __construct(
        protected GameService $gameService
    ) {}

    /**
     * Lista zainstalowanych gier (dla wszystkich zalogowanych)
     */
    public function index(): JsonResponse
    {
        $games = $this->gameService->listGames();

        return response()->json($games->toArray());
    }

    /**
     * Instalacja gry z ZIP (tylko developer)
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()?->isDeveloper()) {
            return response()->json(['message' => 'Brak uprawnień'], 403);
        }

        $request->validate([
            'game_file' => 'required|file|mimes:zip|max:51200',
        ]);

        $file = $request->file('game_file');
        $result = $this->gameService->installFromZip($file->getRealPath());

        if (!$result['success']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json($result);
    }

    /**
     * Odinstalowanie gry (tylko developer)
     */
    public function destroy(string $id): JsonResponse
    {
        if (!request()->user()?->isDeveloper()) {
            return response()->json(['message' => 'Brak uprawnień'], 403);
        }

        $result = $this->gameService->uninstall($id);

        if (!$result['success']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json($result);
    }

    /**
     * Zapisz wynik gry
     */
    public function storeScore(Request $request): JsonResponse
    {
        $request->validate([
            'game_id' => 'required|string|max:64',
            'score' => 'required|integer|min:0',
        ]);

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Nie jesteś zalogowany'], 401);
        }

        GameScore::create([
            'game_id' => $request->game_id,
            'user_id' => $user->id,
            'score' => $request->score,
            'metadata' => $request->metadata ?? null,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Leaderboard dla gry
     */
    public function leaderboard(string $gameId): JsonResponse
    {
        $scores = GameScore::where('game_id', $gameId)
            ->selectRaw('MAX(score) as score, user_id, MAX(created_at) as created_at')
            ->groupBy('user_id')
            ->orderByDesc('score')
            ->limit(20)
            ->get()
            ->load('user:id,name,avatar');

        $result = [];
        $rank = 1;
        foreach ($scores as $s) {
            $result[] = [
                'rank' => $rank++,
                'user_name' => $s->user->name,
                'avatar_url' => $s->user->avatar_url ?? null,
                'score' => (int) $s->score,
                'created_at' => \Carbon\Carbon::parse($s->created_at)->toIso8601String(),
            ];
        }

        return response()->json($result);
    }

    /**
     * Ustawienia gry (publiczne – dla odczytu przez gry)
     */
    public function getSettings(string $gameId): JsonResponse
    {
        $s = GameSetting::getAll($gameId);

        return response()->json($s);
    }

    /**
     * Zapisz ustawienia gry (tylko developer)
     */
    public function updateSettings(Request $request, string $gameId): JsonResponse
    {
        if (!$request->user()?->isDeveloper()) {
            return response()->json(['message' => 'Brak uprawnień'], 403);
        }

        $allowed = match ($gameId) {
            'snake' => ['head_image'],
            default => [],
        };

        if (empty($allowed)) {
            return response()->json(['message' => 'Brak obsługiwanych ustawień dla tej gry'], 422);
        }

        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.head_image' => 'nullable|string|max:500000',
        ])['settings'];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $validated)) {
                GameSetting::set($gameId, $key, $validated[$key]);
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * Serwowanie plików gry (HTML, JS, CSS, obrazy itd.)
     */
    public function serve(string $id, ?string $path = null): Response|BinaryFileResponse
    {
        $id = preg_replace('/[^a-z0-9_-]/i', '', $id);
        $basePath = public_path('games/' . $id);

        if (!File::exists($basePath) || !File::isDirectory($basePath)) {
            abort(404);
        }

        $path = trim((string) $path, '/');
        $filePath = $path !== ''
            ? $basePath . '/' . implode('/', array_map(fn ($p) => preg_replace('/[^a-z0-9_.-]/i', '', $p), explode('/', $path)))
            : $basePath . '/index.html';

        if (!File::exists($filePath) || !File::isFile($filePath)) {
            $filePath = $basePath . '/index.html';
        }

        $realPath = realpath($filePath);
        $realBase = realpath($basePath);
        if (!$realPath || !$realBase || !str_starts_with($realPath, $realBase)) {
            abort(404);
        }

        $mime = match (strtolower(pathinfo($filePath, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html',
            'js' => 'application/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'wasm' => 'application/wasm',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            default => 'application/octet-stream',
        };

        return response()->file($filePath, ['Content-Type' => $mime]);
    }
}
