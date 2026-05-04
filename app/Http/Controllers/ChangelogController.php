<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;

class ChangelogController extends Controller
{
    /**
     * Wyświetl stronę changelogu
     */
    public function index()
    {
        $entries = $this->loadChangelog();

        return inertia('Changelog/Index', [
            'entries' => $entries,
        ]);
    }

    private function loadChangelog(): array
    {
        $path = config_path('changelog.json');
        if (!File::exists($path)) {
            return [];
        }
        try {
            $content = File::get($path);
            $data = json_decode($content, true);
            return $data['entries'] ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
