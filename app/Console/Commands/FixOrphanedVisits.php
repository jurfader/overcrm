<?php

namespace App\Console\Commands;

use App\Models\ClientVisit;
use App\Models\User;
use Illuminate\Console\Command;

class FixOrphanedVisits extends Command
{
    protected $signature = 'migrate:fix-orphaned-visits
                            {--dry-run : Pokaż co zostanie zmienione bez zapisu}';

    protected $description = 'Przypisz wizyty z user_id=null do pierwszego admina (naprawa po migracji)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $count = ClientVisit::whereNull('user_id')->count();

        if ($count === 0) {
            $this->info('Brak wizyt z user_id=null. Nic do naprawy.');
            return self::SUCCESS;
        }

        $fallbackUser = User::where('role', 'admin')->orderBy('id')->first()
            ?? User::orderBy('id')->first();

        if (!$fallbackUser) {
            $this->error('Brak użytkowników w bazie. Nie można przypisać wizyt.');
            return self::FAILURE;
        }

        $this->line("Znaleziono {$count} wizyt bez przypisanego użytkownika.");
        $this->line("Przypisanie do: {$fallbackUser->name} (ID: {$fallbackUser->id})");

        if ($dryRun) {
            $this->warn('Tryb dry-run: zmiany nie zostaną zapisane.');
            return self::SUCCESS;
        }

        $updated = ClientVisit::whereNull('user_id')->update(['user_id' => $fallbackUser->id]);
        $this->info("Zaktualizowano {$updated} wizyt.");

        return self::SUCCESS;
    }
}
