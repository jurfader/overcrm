<?php

namespace Modules\Ringostat\Commands;

use App\Models\Client;
use Illuminate\Console\Command;
use Modules\Ringostat\Services\RingostatService;

class SyncRingostatContacts extends Command
{
    protected $signature = 'ringostat:sync-contacts {--status=active : Status klientów do synchronizacji (active/all)}';
    protected $description = 'Synchronizuj klientów z CRM do książki kontaktów Ringostat';

    public function handle(RingostatService $ringostatService): int
    {
        if (!$ringostatService->isConfigured()) {
            $this->warn('Ringostat nie jest skonfigurowany.');
            return self::SUCCESS;
        }

        $query = Client::query()
            ->where(function ($q) {
                $q->whereNotNull('phone')
                  ->where('phone', '!=', '')
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('phone2')->where('phone2', '!=', '');
                  })
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('contact_phone')->where('contact_phone', '!=', '');
                  });
            });

        $statusFilter = $this->option('status');
        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        $clients = $query->get();

        if ($clients->isEmpty()) {
            $this->info('Brak klientów z numerami telefonu do synchronizacji.');
            return self::SUCCESS;
        }

        $this->info("Synchronizacja {$clients->count()} klientów do Ringostat...");
        $bar = $this->output->createProgressBar($clients->count());

        $synced = 0;
        $failed = 0;

        foreach ($clients as $client) {
            if ($ringostatService->syncContact($client)) {
                $synced++;
            } else {
                $failed++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Zsynchronizowano: {$synced}, pominięto/błędów: {$failed}");

        return self::SUCCESS;
    }
}
