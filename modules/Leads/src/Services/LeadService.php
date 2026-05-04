<?php

namespace Modules\Leads\Services;

use App\Models\Client;
use Modules\Leads\Models\Lead;
use Modules\Leads\Models\LeadActivity;
use Modules\Leads\Models\LeadStatus;

class LeadService
{
    public function createLead(array $data, ?int $userId = null): Lead
    {
        if (empty($data['status_id'])) {
            $default = LeadStatus::where('is_default', true)->first();
            $data['status_id'] = $default?->id ?? LeadStatus::ordered()->first()?->id;
        }

        $lead = Lead::create($data);

        LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $userId,
            'type' => 'created',
            'description' => 'Lead utworzony',
        ]);

        return $lead->load('status', 'assignee');
    }

    public function changeStatus(Lead $lead, int $newStatusId, ?int $userId = null): Lead
    {
        $oldStatus = $lead->status;
        $newStatus = LeadStatus::findOrFail($newStatusId);

        if ($oldStatus->id === $newStatus->id) {
            return $lead;
        }

        $lead->update(['status_id' => $newStatus->id]);

        LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $userId,
            'type' => 'status_changed',
            'description' => "Status: {$oldStatus->name} → {$newStatus->name}",
            'metadata' => [
                'old_status_id' => $oldStatus->id,
                'old_status_name' => $oldStatus->name,
                'new_status_id' => $newStatus->id,
                'new_status_name' => $newStatus->name,
            ],
        ]);

        return $lead->fresh('status', 'assignee');
    }

    public function addNote(Lead $lead, string $note, int $userId): LeadActivity
    {
        $lead->update(['notes' => $lead->notes ? $lead->notes . "\n\n" . $note : $note]);

        return LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $userId,
            'type' => 'note_added',
            'description' => $note,
        ]);
    }

    public function convertToClient(Lead $lead, int $userId): Client
    {
        $client = null;

        // Jeśli lead ma NIP, sprawdź czy klient z tym NIP już istnieje — z soft-deleted włącznie
        // (żeby przywrócić zamiast tworzyć duplikat, gdy klient był wcześniej zmergowany/usunięty)
        if (!empty($lead->nip)) {
            $normalizedNip = preg_replace('/[^0-9]/', '', $lead->nip);
            if (strlen($normalizedNip) === 10) {
                $client = Client::withTrashed()
                    ->whereRaw("REPLACE(REPLACE(REPLACE(nip, ' ', ''), '-', ''), '.', '') = ?", [$normalizedNip])
                    ->first();

                if ($client && $client->trashed()) {
                    $client->restore();
                }
            }
        }

        if (!$client) {
            $client = Client::create([
                'name' => $lead->company_name ?: $lead->name,
                'contact_person' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'nip' => $lead->nip,
                'website' => $lead->website,
                'city' => $lead->city,
                'street' => $lead->address,
                'notes' => $lead->notes,
                'created_by' => $userId,
            ]);
        }

        $terminalStatus = LeadStatus::where('slug', 'klient')->first();

        $lead->update([
            'converted_to_client_id' => $client->id,
            'converted_at' => now(),
            'status_id' => $terminalStatus?->id ?? $lead->status_id,
        ]);

        LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $userId,
            'type' => 'converted',
            'description' => "Skonwertowano do klienta: {$client->name}",
            'metadata' => ['client_id' => $client->id],
        ]);

        return $client;
    }

    public function processAutoTransition(Lead $lead, string $trigger, array $eventData = []): ?Lead
    {
        $currentStatus = $lead->status;
        if (!$currentStatus || empty($currentStatus->auto_rules)) {
            return null;
        }

        foreach ($currentStatus->auto_rules as $rule) {
            if (($rule['trigger'] ?? null) !== $trigger) {
                continue;
            }

            if (!empty($rule['condition']) && ($eventData['condition'] ?? null) !== $rule['condition']) {
                continue;
            }

            $targetStatus = LeadStatus::where('slug', $rule['target_status_slug'] ?? '')->first();
            if ($targetStatus) {
                return $this->changeStatus($lead, $targetStatus->id);
            }
        }

        return null;
    }

    public function getStatistics(): array
    {
        $total = Lead::count();
        $converted = Lead::whereNotNull('converted_to_client_id')->count();
        $byStatus = LeadStatus::ordered()
            ->withCount('leads')
            ->get()
            ->map(fn ($s) => ['name' => $s->name, 'color' => $s->color, 'count' => $s->leads_count]);

        return [
            'total' => $total,
            'converted' => $converted,
            'conversion_rate' => $total > 0 ? round($converted / $total * 100, 1) : 0,
            'this_week' => Lead::where('created_at', '>=', now()->startOfWeek())->count(),
            'by_status' => $byStatus,
        ];
    }
}
