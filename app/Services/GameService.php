<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class GameService
{
    protected string $gamesPath;

    public function __construct()
    {
        $this->gamesPath = public_path('games');
    }

    /**
     * Wbudowane gry (zawsze dostępne)
     */
    protected function builtInGames(): array
    {
        return [
            [
                'id' => 'snake',
                'name' => 'Snake',
                'icon' => 'puzzle',
                'url' => asset('games/snake/index.html'),
            ],
        ];
    }

    /**
     * Skanuj katalog games/ i zwróć listę zainstalowanych gier
     */
    public function listGames(): Collection
    {
        $games = collect($this->builtInGames());
        $seen = $games->pluck('id')->flip();

        if (File::exists($this->gamesPath)) {
            $directories = File::directories($this->gamesPath);

            foreach ($directories as $dir) {
                $manifestPath = $dir . '/game.json';

                if (!File::exists($manifestPath)) {
                    continue;
                }

                $manifest = json_decode(File::get($manifestPath), true);

                if (!$manifest || empty($manifest['id'])) {
                    continue;
                }

                $id = $manifest['id'];
                if ($seen->has($id)) {
                    continue;
                }
                $seen[$id] = true;

                $games->push([
                    'id' => $id,
                    'name' => $manifest['name'] ?? ucfirst($id),
                    'icon' => $manifest['icon'] ?? 'game',
                    'url' => asset('games/' . $id . '/' . ($manifest['entry'] ?? 'index.html')),
                ]);
            }
        } else {
            File::makeDirectory($this->gamesPath, 0755, true);
        }

        return $games->sortBy('name')->values();
    }

    /**
     * Zainstaluj grę z pliku ZIP
     */
    public function installFromZip(string $zipPath): array
    {
        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'message' => 'Nie można otworzyć pliku ZIP'];
        }

        $manifestIndex = $zip->locateName('game.json', \ZipArchive::FL_NODIR);

        if ($manifestIndex === false) {
            $zip->close();
            return ['success' => false, 'message' => 'Brak pliku game.json w archiwum'];
        }

        $manifestContent = $zip->getFromIndex($manifestIndex);
        $manifest = json_decode($manifestContent, true);

        if (!$manifest || empty($manifest['id'])) {
            $zip->close();
            return ['success' => false, 'message' => 'Nieprawidłowy plik game.json (wymagane pole: id)'];
        }

        $gameId = preg_replace('/[^a-z0-9_-]/i', '', $manifest['id']);

        if (empty($gameId)) {
            $zip->close();
            return ['success' => false, 'message' => 'Nieprawidłowy identyfikator gry'];
        }

        $gamePath = $this->gamesPath . '/' . $gameId;

        if (File::exists($gamePath)) {
            $zip->close();
            return ['success' => false, 'message' => 'Gra o tym ID już istnieje'];
        }

        File::makeDirectory($gamePath, 0755, true);
        $zip->extractTo($gamePath);
        $zip->close();

        return [
            'success' => true,
            'message' => 'Gra została zainstalowana',
            'game' => [
                'id' => $gameId,
                'name' => $manifest['name'] ?? ucfirst($gameId),
                'icon' => $manifest['icon'] ?? 'game',
                'url' => asset('games/' . $gameId . '/' . ($manifest['entry'] ?? 'index.html')),
            ],
        ];
    }

    /**
     * Odinstaluj grę
     */
    public function uninstall(string $gameId): array
    {
        $gameId = preg_replace('/[^a-z0-9_-]/i', '', $gameId);

        if (empty($gameId)) {
            return ['success' => false, 'message' => 'Nieprawidłowy identyfikator gry'];
        }

        $builtInIds = collect($this->builtInGames())->pluck('id')->toArray();
        if (in_array($gameId, $builtInIds)) {
            return ['success' => false, 'message' => 'Nie można odinstalować wbudowanej gry'];
        }

        $gamePath = $this->gamesPath . '/' . $gameId;

        if (!File::exists($gamePath)) {
            return ['success' => false, 'message' => 'Gra nie istnieje'];
        }

        File::deleteDirectory($gamePath);

        return ['success' => true, 'message' => 'Gra została odinstalowana'];
    }
}
