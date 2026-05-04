<?php

namespace App\Http\Controllers;

use App\Models\GbaSaveState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class GbaController extends Controller
{
    protected string $romsPath;

    public function __construct()
    {
        $this->romsPath = storage_path('app/gba-roms');
    }

    protected function sanitizeRomKey(string $key): string
    {
        $key = basename($key);

        return preg_replace('/[^a-zA-Z0-9_. ()-]/', '', $key);
    }

    /**
     * Lista dostępnych ROMów (.gba)
     */
    public function listRoms(Request $request): JsonResponse
    {
        if (! $request->user()) {
            return response()->json(['message' => 'Zaloguj się'], 401);
        }

        if (! File::isDirectory($this->romsPath)) {
            File::makeDirectory($this->romsPath, 0755, true);
            return response()->json(['roms' => []]);
        }

        $files = File::files($this->romsPath);
        $roms = [];
        foreach ($files as $file) {
            $ext = strtolower($file->getExtension());
            if ($ext === 'gba' || $ext === 'gbc' || $ext === 'gb') {
                $roms[] = [
                    'key' => $file->getFilename(),
                    'name' => pathinfo($file->getFilename(), PATHINFO_FILENAME),
                ];
            }
        }

        return response()->json(['roms' => $roms]);
    }

    /**
     * Serwowanie pliku ROM
     */
    public function serveRom(Request $request, string $filename): Response|BinaryFileResponse
    {
        if (! $request->user()) {
            abort(401);
        }

        $filename = $this->sanitizeRomKey($filename);
        $path = $this->romsPath . '/' . $filename;

        if (! File::exists($path) || ! File::isFile($path)) {
            abort(404);
        }

        $realPath = realpath($path);
        $realBase = realpath($this->romsPath);
        if (! $realPath || ! $realBase || ! str_starts_with($realPath, $realBase)) {
            abort(404);
        }

        $mime = match (strtolower(File::extension($path))) {
            'gba', 'gbc', 'gb' => 'application/octet-stream',
            default => 'application/octet-stream',
        };

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
        ]);
    }

    /**
     * ROM z podpisem (dla gba.ninja – bez auth, ważny 10 min)
     */
    public function serveRomSigned(Request $request, string $filename): Response|BinaryFileResponse
    {
        $filename = $this->sanitizeRomKey($filename);
        $path = $this->romsPath . '/' . $filename;

        if (! File::exists($path) || ! File::isFile($path)) {
            abort(404);
        }

        $realPath = realpath($path);
        $realBase = realpath($this->romsPath);
        if (! $realPath || ! $realBase || ! str_starts_with($realPath, $realBase)) {
            abort(404);
        }

        $mime = match (strtolower(File::extension($path))) {
            'gba', 'gbc', 'gb' => 'application/octet-stream',
            default => 'application/octet-stream',
        };

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Pobierz zapisany stan gry (dla EJS_loadStateURL)
     */
    public function getSaveState(Request $request, string $romKey): Response|BinaryFileResponse
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $romKey = $this->sanitizeRomKey($romKey);
        $save = GbaSaveState::where('user_id', $user->id)
            ->where('rom_key', $romKey)
            ->first();

        if (! $save) {
            abort(404);
        }

        return response($save->save_data, 200, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    /**
     * Zapisz stan gry (POST z emulatora – raw body lub multipart)
     */
    public function saveSaveState(Request $request, string $romKey): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Zaloguj się'], 401);
        }

        $romKey = $this->sanitizeRomKey($romKey);

        $saveData = null;
        $screenshot = null;

        if ($request->hasFile('save_data')) {
            $saveData = $request->file('save_data')->get();
        } elseif ($request->hasFile('save_state')) {
            $saveData = $request->file('save_state')->get();
        } elseif ($request->filled('save_data')) {
            $v = $request->input('save_data');
            $saveData = is_string($v) && str_starts_with($v, 'data:') ? base64_decode(explode(',', $v)[1] ?? '') : $v;
        } else {
            $saveData = $request->getContent();
        }

        if ($request->hasFile('screenshot')) {
            $screenshot = $request->file('screenshot')->get();
        }

        if (empty($saveData) || strlen($saveData) < 100) {
            return response()->json(['message' => 'Nieprawidłowe dane zapisu'], 422);
        }

        if (strlen($saveData) > 5 * 1024 * 1024) {
            return response()->json(['message' => 'Zapis jest za duży'], 422);
        }

        GbaSaveState::updateOrCreate(
            [
                'user_id' => $user->id,
                'rom_key' => $romKey,
            ],
            array_filter([
                'save_data' => $saveData,
                'screenshot' => $screenshot,
            ])
        );

        return response()->json(['success' => true]);
    }

    /**
     * Strona emulatora (EmulatorJS) – w iframe w plannerze
     */
    public function play(Request $request, string $romKey): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $romKey = $this->sanitizeRomKey($romKey);
        $path = $this->romsPath . '/' . $romKey;

        if (! File::exists($path) || ! File::isFile($path)) {
            abort(404);
        }

        $realPath = realpath($path);
        $realBase = realpath($this->romsPath);
        if (! $realPath || ! $realBase || ! str_starts_with($realPath, $realBase)) {
            abort(404);
        }

        $romUrl = url('/games/gba/roms/' . rawurlencode($romKey));
        $saveLoadUrl = url('/games/gba/saves/' . rawurlencode($romKey));

        $hasSave = GbaSaveState::where('user_id', $user->id)
            ->where('rom_key', $romKey)
            ->exists();

        $core = match (strtolower(File::extension($romKey))) {
            'gbc', 'gb' => 'gb',
            default => 'gba',
        };

        $csrfToken = csrf_token();
        $html = $this->getEmulatorHtml($romUrl, $saveLoadUrl, $romKey, $core, $csrfToken, $hasSave);

        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    protected function getEmulatorHtml(string $romUrl, string $saveLoadUrl, string $romKey, string $core, string $csrfToken = '', bool $hasSave = false): string
    {
        $gameName = htmlspecialchars(pathinfo($romKey, PATHINFO_FILENAME), ENT_QUOTES, 'UTF-8');
        $loadStateUrl = $hasSave ? $saveLoadUrl : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{$csrfToken}">
  <title>GBA - {$gameName}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      background: #0c1222;
      color: #e4e8f0;
      font-family: 'Inter', -apple-system, sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 16px;
    }
    .header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      max-width: 800px;
      margin-bottom: 12px;
    }
    .title { font-size: 1rem; color: #94a3b8; }
    .btn {
      padding: 8px 16px;
      border-radius: 6px;
      border: none;
      font-size: 0.875rem;
      font-weight: 500;
      font-family: inherit;
      cursor: pointer;
      background: #334155;
      color: #e2e8f0;
      text-decoration: none;
    }
    .btn:hover { background: #475569; }
    #game {
      width: 100%; max-width: 800px; height: 480px; min-height: 480px;
      tabindex: 0;
      outline: none;
      cursor: default;
    }
  </style>
</head>
<body>
  <div class="header">
    <span class="title">{$gameName}</span>
    <a href="#" class="btn" onclick="history.back(); return false;">← Powrót</a>
  </div>
  <p style="font-size:0.75rem;color:#64748b;margin-bottom:8px">Kliknij przycisk Start w emulatorze, aby uruchomić grę. <strong>Kliknij w emulator</strong>, aby sterować klawiaturą.</p>
  <div id="game" tabindex="0"></div>
  <script>
    EJS_player = "#game";
    EJS_core = "{$core}";
    EJS_pathtodata = "https://cdn.emulatorjs.org/stable/data/";
    EJS_gameUrl = "{$romUrl}";
    EJS_gameName = "{$gameName}";
    EJS_loadStateURL = "{$loadStateUrl}";
    EJS_language = "en-US";
    EJS_threads = false;
    EJS_startOnLoaded = false;
    EJS_ready = function() {
      console.log("Emulator ready");
      var gameEl = document.getElementById("game");
      if (gameEl) {
        gameEl.addEventListener("click", function() {
          var canvas = gameEl.querySelector("canvas");
          (canvas || gameEl).focus();
        });
        var canvas = gameEl.querySelector("canvas");
        if (canvas) canvas.setAttribute("tabindex", "0");
      }
    };
    EJS_fail = function(e) {
      var el = document.getElementById("game");
      if (el) el.innerHTML = '<div style="padding:24px;color:#ef4444;text-align:center;background:#1e293b;border-radius:8px">' +
        '<strong>Emulator nie wystartował</strong><br><br>Na macOS spróbuj Chrome lub Firefox. Safari może mieć problemy z WebGL.<br><br>' +
        '<small style="color:#64748b">' + (e && e.message ? e.message : "Błąd WebGL/WASM") + '</small></div>';
      console.error("EmulatorJS error:", e);
    };
    EJS_onSaveState = function(e) {
      if (!e || !Array.isArray(e) || !e[1]) return;
      var saveData = e[1];
      var blob = saveData instanceof Blob ? saveData : new Blob([saveData]);
      var formData = new FormData();
      formData.append("save_data", blob, "state.sav");
      formData.append("_token", document.querySelector('meta[name="csrf-token"]')?.content || "");
      if (e[0] && (e[0] instanceof Blob || e[0] instanceof ArrayBuffer)) {
        formData.append("screenshot", e[0] instanceof Blob ? e[0] : new Blob([e[0]]));
      }
      fetch("{$saveLoadUrl}", {
        method: "POST",
        body: formData,
        credentials: "include",
        headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" }
      }).then(function(r) {
        if (r.ok) console.log("Zapisano na serwer");
        else console.error("Błąd zapisu");
      }).catch(function(err) { console.error(err); });
    };
  </script>
  <script src="https://cdn.emulatorjs.org/stable/data/loader.js" crossorigin="anonymous"></script>
</body>
</html>
HTML;
    }
}
