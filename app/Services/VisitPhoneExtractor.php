<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wyciąga numery telefonów z tekstu opisu wizyty.
 * Używa regexa + opcjonalnie Gemini dla przypadków rozsianych tekstów ("zadzwonił pan Kowalski pięćset..").
 */
class VisitPhoneExtractor
{
    public function extract(string $text): array
    {
        $text = strip_tags($text);
        if (trim($text) === '') return [];

        // Regex — polski format: +48 XXX XXX XXX, 9-cyfrowy, z myślnikami/spacjami
        $regex = '/(?:\+?48[\s\-]?)?(?:\d{3}[\s\-]?\d{3}[\s\-]?\d{3}|\d{2}[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}|\d{9})/';
        preg_match_all($regex, $text, $matches);

        $phones = [];
        foreach ($matches[0] ?? [] as $raw) {
            $normalized = preg_replace('/\D+/', '', $raw);
            if (strlen($normalized) > 9 && str_starts_with($normalized, '48')) {
                $normalized = substr($normalized, 2);
            }
            // Odrzuć zbyt krótkie lub fragmenty NIP/REGON/innych liczb
            if (strlen($normalized) !== 9) continue;
            $phones[$normalized] = $this->formatPhone($normalized);
        }

        return array_values($phones);
    }

    /**
     * Formatuje numer do czytelnej formy: "500 123 456"
     */
    private function formatPhone(string $digits): string
    {
        if (strlen($digits) !== 9) return $digits;
        return substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 3);
    }
}
