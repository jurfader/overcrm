<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scalanie duplikatów klientów — zachowuje wszystkie kontakty i adresy.
 * Używane przez commandy clients:merge (pojedyncze) i clients:merge-auto (batch).
 */
class ClientMergeService
{
    /** Tabele referujące do clients.id (przepinane z dup na keep). */
    public const RELATED_TABLES = [
        'client_visits'    => 'client_id',
        'tasks'            => 'client_id',
        'sent_emails'      => 'client_id',
        'client_summaries' => 'client_id',
        'ringostat_calls'  => 'client_id',
        'leads'            => 'converted_to_client_id',
    ];

    public const FILLABLE_SIMPLE = [
        'short_name', 'nip', 'regon', 'website',
        'country', 'client_status', 'assigned_to', 'birthday',
    ];

    public const PHONE_SLOTS = ['phone', 'phone2', 'contact_phone'];
    public const EMAIL_SLOTS = ['email', 'contact_email'];
    public const ADDRESS_FIELDS = ['street', 'building_number', 'apartment_number', 'postal_code', 'city'];

    /**
     * Zaplanuj merge — zwraca co zostanie zmienione, bez żadnego zapisu do DB.
     * Struktura:
     *   [
     *     'patches' => ['field' => 'value'],       // pola do update w keep
     *     'overflow' => ['tel: 500...', 'email:..'], // nadmiarowe do notatek
     *     'relations' => ['table' => count],      // ile rekordów przepniemy
     *   ]
     */
    public function plan(Client $keep, Client $dup): array
    {
        $patches = [];

        // Proste pola
        foreach (self::FILLABLE_SIMPLE as $field) {
            if (empty($keep->$field) && !empty($dup->$field)) {
                $patches[$field] = $dup->$field;
            }
        }

        // Kontakty + adresy
        $contactResult = $this->planContactMerge($keep, $dup);
        $patches = array_merge($patches, $contactResult['patches']);

        // Relacje
        $relations = [];
        foreach (self::RELATED_TABLES as $table => $column) {
            if (!Schema::hasTable($table)) continue;
            $cnt = DB::table($table)->where($column, $dup->id)->count();
            if ($cnt > 0) $relations[$table] = $cnt;
        }

        return [
            'patches'   => $patches,
            'overflow'  => $contactResult['overflow'],
            'relations' => $relations,
        ];
    }

    /**
     * Wykonaj merge w transakcji. Zwraca liczbę przepiętych rekordów z relacji.
     */
    public function execute(Client $keep, Client $dup): int
    {
        $plan = $this->plan($keep, $dup);
        $totalRelations = array_sum($plan['relations']);

        DB::transaction(function () use ($keep, $dup, $plan) {
            // 1. Przepnij relacje
            foreach (self::RELATED_TABLES as $table => $column) {
                if (!Schema::hasTable($table)) continue;
                DB::table($table)->where($column, $dup->id)->update([$column => $keep->id]);
            }

            // 2. Uzupełnij pola keep
            if (!empty($plan['patches'])) {
                $keep->update($plan['patches']);
            }

            // 3. Scal notatki (oryginał dup + nadmiarowe kontakty)
            $notesParts = [];
            if ($keep->notes) $notesParts[] = $keep->notes;

            $dupAppend = [];
            if (!empty($plan['overflow'])) {
                $dupAppend[] = 'Dodatkowe kontakty: ' . implode('; ', $plan['overflow']);
            }
            if (!empty($dup->notes) && $dup->notes !== $keep->notes) {
                $dupAppend[] = $dup->notes;
            }

            if (!empty($dupAppend)) {
                $notesParts[] = "--- scalone z ID {$dup->id} ({$dup->name}) ---\n" . implode("\n", $dupAppend);
            }

            if (!empty($notesParts)) {
                $keep->update(['notes' => trim(implode("\n\n", $notesParts))]);
            }

            // 4. Soft-delete duplikat
            $dup->delete();
        });

        return $totalRelations;
    }

    private function planContactMerge(Client $keep, Client $dup): array
    {
        $patches = [];
        $overflow = [];

        // Telefony
        $existingPhones = [];
        foreach (self::PHONE_SLOTS as $slot) {
            if (!empty($keep->$slot)) {
                $existingPhones[$this->normalizePhone($keep->$slot)] = true;
            }
        }

        $dupPhones = array_filter([$dup->phone, $dup->phone2, $dup->contact_phone]);
        foreach ($dupPhones as $phone) {
            $norm = $this->normalizePhone($phone);
            if (isset($existingPhones[$norm])) continue;

            $slotToFill = $this->findFreeSlot($keep, $patches, self::PHONE_SLOTS);
            if ($slotToFill) {
                $patches[$slotToFill] = $phone;
                $existingPhones[$norm] = true;
            } else {
                $overflow[] = "tel: {$phone}";
            }
        }

        // Emaile
        $existingEmails = [];
        foreach (self::EMAIL_SLOTS as $slot) {
            if (!empty($keep->$slot)) {
                $existingEmails[strtolower(trim($keep->$slot))] = true;
            }
        }

        $dupEmails = array_filter([$dup->email, $dup->contact_email]);
        foreach ($dupEmails as $email) {
            $norm = strtolower(trim($email));
            if (isset($existingEmails[$norm])) continue;

            $slotToFill = $this->findFreeSlot($keep, $patches, self::EMAIL_SLOTS);
            if ($slotToFill) {
                $patches[$slotToFill] = $email;
                $existingEmails[$norm] = true;
            } else {
                $overflow[] = "email: {$email}";
            }
        }

        // Osoba kontaktowa
        if (!empty($dup->contact_person) && $dup->contact_person !== $keep->contact_person) {
            if (empty($keep->contact_person)) {
                $patches['contact_person'] = $dup->contact_person;
            } else {
                $overflow[] = "osoba kontaktowa: {$dup->contact_person}";
            }
        }

        // Adres
        $keepFullAddr = $this->buildFullAddress($keep);
        $dupFullAddr = $this->buildFullAddress($dup);

        if (empty($keepFullAddr) && !empty($dupFullAddr)) {
            foreach (self::ADDRESS_FIELDS as $f) {
                if (!empty($dup->$f)) $patches[$f] = $dup->$f;
            }
        } elseif (!empty($dupFullAddr) && $this->normalizeAddress($keepFullAddr) !== $this->normalizeAddress($dupFullAddr)) {
            $overflow[] = "dodatkowy adres: {$dupFullAddr}";
        }

        return ['patches' => $patches, 'overflow' => $overflow];
    }

    private function findFreeSlot(Client $keep, array $patches, array $slots): ?string
    {
        foreach ($slots as $slot) {
            if (empty($keep->$slot) && !isset($patches[$slot])) {
                return $slot;
            }
        }
        return null;
    }

    private function buildFullAddress(Client $client): string
    {
        $line1 = trim(($client->street ?? '') . ' ' . ($client->building_number ?? '') . ($client->apartment_number ? '/' . $client->apartment_number : ''));
        $line2 = trim(($client->postal_code ?? '') . ' ' . ($client->city ?? ''));
        return trim($line1 . ($line1 && $line2 ? ', ' : '') . $line2);
    }

    private function normalizeAddress(string $addr): string
    {
        return strtolower(preg_replace('/\s+|[.,\/]/', '', $addr));
    }

    private function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone);
        if (strlen($normalized) > 9 && str_starts_with($normalized, '48')) {
            $normalized = substr($normalized, 2);
        }
        return $normalized;
    }
}
