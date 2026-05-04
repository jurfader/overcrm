<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ChangelogFromGit extends Command
{
    protected $signature = 'changelog:from-git
                            {--compare=production/main : Gałąź/ref do porównania (commity od tej gałęzi do HEAD)}
                            {--append : Dopisz wpis do config/changelog.json}
                            {--ver= : Wersja (np. 1.3.0), domyślnie auto-bump}
                            {--date= : Data w formacie YYYY-MM-DD, domyślnie dziś}';

    protected $description = 'Generuje wpis changelogu z commitów git (feat:, fix:, remove:)';

    public function handle(): int
    {
        $compare = $this->option('compare');
        $basePath = base_path();

        // Pobierz commity
        $cmd = "cd " . escapeshellarg($basePath) . " && git log {$compare}..HEAD --pretty=format:%s 2>/dev/null";
        $output = trim(shell_exec($cmd) ?? '');

        if (empty($output)) {
            $this->warn("Brak nowych commitów względem {$compare}. Uruchom: git fetch production");
            return Command::SUCCESS;
        }

        $lines = array_filter(array_map('trim', explode("\n", $output)));
        $added = [];
        $fixed = [];
        $removed = [];

        foreach ($lines as $msg) {
            $text = $this->extractMessage($msg);
            if (empty($text)) {
                continue;
            }
            $lower = strtolower($msg);
            if (str_starts_with($lower, 'feat:') || str_starts_with($lower, 'dodano:')) {
                $added[] = $text;
            } elseif (str_starts_with($lower, 'fix:') || str_starts_with($lower, 'naprawiono:')) {
                $fixed[] = $text;
            } elseif (str_starts_with($lower, 'remove:') || str_starts_with($lower, 'usunięto:') || str_starts_with($lower, 'chore: remove')) {
                $removed[] = $text;
            } else {
                // Inne commity – traktuj jako fix
                $fixed[] = $text;
            }
        }

        $date = $this->option('date') ?: now()->format('Y-m-d');
        $version = $this->option('ver') ?: $this->suggestVersion($added, $fixed, $removed);

        $entry = [
            'date' => $date,
            'version' => $version,
            'added' => array_values(array_unique($added)),
            'fixed' => array_values(array_unique($fixed)),
            'removed' => array_values(array_unique($removed)),
        ];

        $this->info('Proponowany wpis changelogu:');
        $this->newLine();
        $this->line(json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->newLine();

        if ($this->option('append')) {
            return $this->appendToChangelog($entry);
        }

        $this->comment('Użyj --append aby dopisać do config/changelog.json');
        return Command::SUCCESS;
    }

    private function extractMessage(string $msg): string
    {
        if (preg_match('/^(feat|fix|remove|chore|dodano|naprawiono|usunięto):\s*(.+)/i', $msg, $m)) {
            return trim($m[2]);
        }
        return trim($msg);
    }

    private function suggestVersion(array $added, array $fixed, array $removed): string
    {
        $path = config_path('changelog.json');
        if (!File::exists($path)) {
            return '1.0.0';
        }
        try {
            $data = json_decode(File::get($path), true);
            $entries = $data['entries'] ?? [];
            $last = $entries[0] ?? null;
            if (!$last || empty($last['version'])) {
                return '1.0.0';
            }
            $v = $last['version'];
            if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $v, $m)) {
                return '1.0.0';
            }
            $major = (int) $m[1];
            $minor = (int) $m[2];
            $patch = (int) $m[3];
            if (!empty($added) || !empty($removed)) {
                return "{$major}." . ($minor + 1) . ".0";
            }
            return "{$major}.{$minor}." . ($patch + 1);
        } catch (\Throwable $e) {
            return '1.0.0';
        }
    }

    private function appendToChangelog(array $entry): int
    {
        $path = config_path('changelog.json');
        $data = ['entries' => []];
        if (File::exists($path)) {
            $data = json_decode(File::get($path), true) ?? $data;
        }
        array_unshift($data['entries'], $entry);
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Dodano wpis v{$entry['version']} do config/changelog.json");
        return Command::SUCCESS;
    }
}
