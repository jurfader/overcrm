<?php

namespace App\Services;

use App\Services\Traits\LogsApiCalls;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GusService
{
    use LogsApiCalls;
    private string $apiKey;
    private string $apiUrl;
    private ?string $sessionId = null;

    private const BIR_DEFAULT_URL = 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';
    private const BIR_ACTION_PREFIX = 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/';

    public function __construct()
    {
        $this->apiKey = config('services.gus.api_key', '');
        
        if (empty($this->apiKey)) {
            $this->apiKey = \App\Models\Setting::get('gus_api_key', '', 'core');
        }
        
        $this->apiUrl = config('services.gus.url', self::BIR_DEFAULT_URL);
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Pobierz dane firmy po NIP — próbuje BIR, potem Biała Lista, potem fallback
     */
    public function getByNip(string $nip): ?array
    {
        $nip = preg_replace('/[^0-9]/', '', $nip);
        
        if (strlen($nip) !== 10) {
            return null;
        }

        $cacheKey = "gus_nip_{$nip}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $result = $this->lookupBir($nip);

        if (!$result) {
            $result = $this->lookupBialaLista($nip);
        }

        if (!$result) {
            $result = $this->fallbackLookup($nip);
        }

        if ($result && !empty($result['name'])) {
            Cache::put($cacheKey, $result, 86400);
        }

        return $result;
    }

    /**
     * GUS BIR 1.1 SOAP API — pełna nazwa, adres rozdzielony, REGON, nazwa skrócona
     */
    private function lookupBir(string $nip): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $sessionId = $this->birLogin();
            if (!$sessionId) {
                return null;
            }

            $searchXml = <<<XML
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="http://CIS/BIR/PUBL/2014/07" xmlns:dat="http://CIS/BIR/PUBL/2014/07/DataContract">
  <soap:Header xmlns:wsa="http://www.w3.org/2005/08/addressing">
    <wsa:Action>{$this->actionUrl('DaneSzukajPodmioty')}</wsa:Action>
    <wsa:To>{$this->apiUrl}</wsa:To>
  </soap:Header>
  <soap:Body>
    <ns:DaneSzukajPodmioty>
      <ns:pParametryWyszukiwania>
        <dat:Nip>{$nip}</dat:Nip>
      </ns:pParametryWyszukiwania>
    </ns:DaneSzukajPodmioty>
  </soap:Body>
</soap:Envelope>
XML;

            $response = Http::withHeaders([
                'Content-Type' => 'application/soap+xml;charset=UTF-8',
                'sid' => $sessionId,
            ])->withBody($searchXml, 'application/soap+xml;charset=UTF-8')
              ->timeout(10)
              ->post($this->apiUrl);

            if (!$response->successful()) {
                Log::warning('GUS BIR search failed: HTTP ' . $response->status());
                return null;
            }

            return $this->parseBirSearchResponse($response->body(), $nip, $sessionId);

        } catch (\Exception $e) {
            Log::warning('GUS BIR lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    private function actionUrl(string $method): string
    {
        return self::BIR_ACTION_PREFIX . $method;
    }

    private function birLogin(): ?string
    {
        $xml = <<<XML
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="http://CIS/BIR/PUBL/2014/07">
  <soap:Header xmlns:wsa="http://www.w3.org/2005/08/addressing">
    <wsa:Action>{$this->actionUrl('Zaloguj')}</wsa:Action>
    <wsa:To>{$this->apiUrl}</wsa:To>
  </soap:Header>
  <soap:Body>
    <ns:Zaloguj>
      <ns:pKluczUzytkownika>{$this->apiKey}</ns:pKluczUzytkownika>
    </ns:Zaloguj>
  </soap:Body>
</soap:Envelope>
XML;

        $response = Http::withHeaders([
            'Content-Type' => 'application/soap+xml;charset=UTF-8',
        ])->withBody($xml, 'application/soap+xml;charset=UTF-8')
          ->timeout(10)
          ->post($this->apiUrl);

        if (!$response->successful()) {
            Log::warning('GUS BIR login failed: HTTP ' . $response->status());
            return null;
        }

        preg_match('/<ZalogujResult>(.*?)<\/ZalogujResult>/s', $response->body(), $matches);
        $sid = $matches[1] ?? null;

        if (empty($sid)) {
            Log::warning('GUS BIR login: empty session ID');
        }

        return $sid;
    }

    private function parseBirSearchResponse(string $body, string $nip, string $sessionId): ?array
    {
        preg_match('/<DaneSzukajPodmiotyResult>(.*?)<\/DaneSzukajPodmiotyResult>/s', $body, $matches);
        $encoded = $matches[1] ?? '';

        if (empty($encoded)) {
            return null;
        }

        $decoded = html_entity_decode($encoded);

        try {
            $decoded = preg_replace('/&#x[0-9A-Fa-f]+;/', '', $decoded);
            $xml = new \SimpleXMLElement(trim($decoded));
        } catch (\Exception $e) {
            Log::warning('GUS BIR parse error: ' . $e->getMessage());
            return null;
        }

        if (!isset($xml->dane)) {
            return null;
        }

        $dane = $xml->dane;
        $name = trim((string)($dane->Nazwa ?? ''));
        $regon = trim((string)($dane->Regon ?? ''));
        $type = trim((string)($dane->Typ ?? ''));
        $silosId = trim((string)($dane->SilosID ?? ''));

        $result = [
            'name' => $name,
            'short_name' => '',
            'nip' => $nip,
            'regon' => $regon,
            'street' => trim((string)($dane->Ulica ?? '')),
            'building_number' => trim((string)($dane->NrNieruchomosci ?? '')),
            'apartment_number' => trim((string)($dane->NrLokalu ?? '')),
            'postal_code' => trim((string)($dane->KodPocztowy ?? '')),
            'city' => trim((string)($dane->Miejscowosc ?? '')),
            'address' => '',
        ];

        $shortName = $this->fetchShortNameFromReport($sessionId, $regon, $type, $silosId);
        $result['short_name'] = $shortName ?: $this->generateShortName($name);

        return $result;
    }

    /**
     * Pobiera NazwaSkrocona z pełnego raportu BIR
     */
    private function fetchShortNameFromReport(string $sessionId, string $regon, string $type, string $silosId): ?string
    {
        if (empty($regon)) {
            return null;
        }

        $reportName = '';
        $shortNameField = '';

        if ($type === 'P') {
            $reportName = 'BIR11OsPrawna';
            $shortNameField = 'praw_nazwaSkrocona';
        } elseif ($type === 'F') {
            if ($silosId === '1') {
                $reportName = 'BIR11OsFizycznaDzialalnoscCeidg';
                $shortNameField = 'fiz_nazwaSkrocona';
            } elseif ($silosId === '2') {
                $reportName = 'BIR11OsFizycznaDzialalnoscRolnicza';
                $shortNameField = 'fiz_nazwaSkrocona';
            } elseif ($silosId === '3') {
                $reportName = 'BIR11OsFizycznaDzialalnoscPozostala';
                $shortNameField = 'fiz_nazwaSkrocona';
            }
        }

        if (empty($reportName)) {
            return null;
        }

        try {
            $xml = <<<XML
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="http://CIS/BIR/PUBL/2014/07">
  <soap:Header xmlns:wsa="http://www.w3.org/2005/08/addressing">
    <wsa:Action>{$this->actionUrl('DanePobierzPelnyRaport')}</wsa:Action>
    <wsa:To>{$this->apiUrl}</wsa:To>
  </soap:Header>
  <soap:Body>
    <ns:DanePobierzPelnyRaport>
      <ns:pRegon>{$regon}</ns:pRegon>
      <ns:pNazwaRaportu>{$reportName}</ns:pNazwaRaportu>
    </ns:DanePobierzPelnyRaport>
  </soap:Body>
</soap:Envelope>
XML;

            $response = Http::withHeaders([
                'Content-Type' => 'application/soap+xml;charset=UTF-8',
                'sid' => $sessionId,
            ])->withBody($xml, 'application/soap+xml;charset=UTF-8')
              ->timeout(10)
              ->post($this->apiUrl);

            if (!$response->successful()) {
                return null;
            }

            preg_match('/<DanePobierzPelnyRaportResult>(.*?)<\/DanePobierzPelnyRaportResult>/s', $response->body(), $matches);
            $encoded = $matches[1] ?? '';

            if (empty($encoded)) {
                return null;
            }

            $decoded = html_entity_decode($encoded);
            $decoded = preg_replace('/&#x[0-9A-Fa-f]+;/', '', $decoded);
            $reportXml = new \SimpleXMLElement(trim($decoded));

            if (isset($reportXml->dane)) {
                if (isset($reportXml->dane->ErrorCode)) {
                    return null;
                }
                $shortName = trim((string)($reportXml->dane->{$shortNameField} ?? ''));
                return !empty($shortName) ? $shortName : null;
            }
        } catch (\Exception $e) {
            Log::warning("GUS BIR report ({$reportName}) failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Biała Lista VAT API — fallback gdy BIR niedostępny
     */
    private function lookupBialaLista(string $nip): ?array
    {
        try {
            return $this->loggedRequest('gus', 'GET', "search/nip/{$nip}", function () use ($nip) {
                $response = Http::timeout(10)
                    ->get("https://wl-api.mf.gov.pl/api/search/nip/{$nip}", [
                        'date' => now()->format('Y-m-d'),
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $subject = $data['result']['subject'] ?? ($data['result']['subjects'] ?? [])[0] ?? null;

                    if ($subject) {
                        $rawAddress = $subject['workingAddress'] ?? $subject['residenceAddress'] ?? '';
                        $parsed = $this->parseFullAddress($rawAddress);
                        $companyName = trim($subject['representatives'][0]['companyName'] ?? '');
                        $subjectName = trim($subject['name'] ?? '');
                        // Dla JDG companyName ma pełną nazwę (np. "OVERMEDIA Paweł Roloff"), subject.name tylko osobę ("PAWEŁ ROLOFF")
                        $name = !empty($companyName) ? $companyName : $subjectName;
                        if (empty($name)) {
                            $name = 'Firma (NIP ' . $nip . ')';
                        }
                        $shortName = $this->generateShortName($name);
                        if ($shortName === $name && preg_match('/^(\S+)\s+.+/', $name, $m)) {
                            $shortName = $m[1];
                        }

                        return [
                            'name' => $name,
                            'short_name' => $shortName,
                            'nip' => $nip,
                            'regon' => $subject['regon'] ?? '',
                            'street' => $parsed['street'],
                            'building_number' => $parsed['building_number'],
                            'apartment_number' => $parsed['apartment_number'],
                            'postal_code' => $parsed['postal_code'],
                            'city' => $parsed['city'],
                            'address' => $rawAddress,
                            'status' => $subject['statusVat'] ?? 'unknown',
                        ];
                    }
                }

                return null;
            }, ['nip' => $nip]);
        } catch (\Exception $e) {
            Log::error('Biała Lista API Error: ' . $e->getMessage());
            return null;
        }
    }

    private function fallbackLookup(string $nip): ?array
    {
        try {
            $response = Http::timeout(10)
                ->get("https://rejestr.io/api/v2/org", [
                    'nip' => $nip,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (!empty($data)) {
                    $company = $data[0] ?? $data;
                    
                    return [
                        'name' => $company['name'] ?? '',
                        'short_name' => $company['shortName'] ?? $this->generateShortName($company['name'] ?? ''),
                        'nip' => $nip,
                        'regon' => $company['regon'] ?? '',
                        'street' => $company['street'] ?? '',
                        'building_number' => $company['buildingNumber'] ?? '',
                        'apartment_number' => $company['apartmentNumber'] ?? '',
                        'postal_code' => $company['postalCode'] ?? '',
                        'city' => $company['city'] ?? '',
                        'address' => $company['address'] ?? '',
                        'status' => 'active',
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::error('Fallback GUS API Error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Parsuje pełny adres z Białej Listy: "ul. Hoża 10/35, 87-800 Włocławek"
     */
    private function parseFullAddress(string $address): array
    {
        $result = [
            'street' => '',
            'building_number' => '',
            'apartment_number' => '',
            'postal_code' => '',
            'city' => '',
        ];

        if (empty($address)) {
            return $result;
        }

        if (preg_match('/(\d{2}-\d{3})/', $address, $matches)) {
            $result['postal_code'] = $matches[1];
        }

        if (preg_match('/\d{2}-\d{3}\s+(.+)$/', $address, $matches)) {
            $result['city'] = trim($matches[1]);
        }

        $streetPart = '';
        if (preg_match('/^(.+?),\s*\d{2}-\d{3}/', $address, $matches)) {
            $streetPart = trim($matches[1]);
        } elseif (preg_match('/^(.+?)\s+\d{2}-\d{3}/', $address, $matches)) {
            $streetPart = trim($matches[1]);
        }

        if ($streetPart) {
            $streetPart = preg_replace('/^ul\.\s*/i', '', $streetPart);

            if (preg_match('/^(.+?)\s+(\d+[a-zA-Z]?)(?:[\/\\\\](\d+[a-zA-Z]?))?$/', $streetPart, $matches)) {
                $result['street'] = trim($matches[1]);
                $result['building_number'] = $matches[2];
                if (!empty($matches[3])) {
                    $result['apartment_number'] = $matches[3];
                }
            } else {
                $result['street'] = $streetPart;
            }
        }

        return $result;
    }

    private function generateShortName(string $name): string
    {
        $name = trim($name);
        if (empty($name)) {
            return '';
        }

        if (mb_strlen($name) <= 40) {
            return $name;
        }

        $shortForms = [
            'SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ' => 'SP. Z O.O.',
            'SPOLKA Z OGRANICZONA ODPOWIEDZIALNOSCIA' => 'SP. Z O.O.',
            'SPÓŁKA AKCYJNA' => 'S.A.',
            'SPÓŁKA JAWNA' => 'SP.J.',
            'SPÓŁKA KOMANDYTOWA' => 'SP.K.',
            'SPÓŁKA KOMANDYTOWO-AKCYJNA' => 'S.K.A.',
            'SPÓŁKA PARTNERSKA' => 'SP.P.',
        ];
        
        $short = $name;
        foreach ($shortForms as $long => $abbreviated) {
            if (mb_stripos($short, $long) !== false) {
                $short = str_ireplace($long, $abbreviated, $short);
                break;
            }
        }
        
        return trim($short);
    }

    public static function validateNip(string $nip): bool
    {
        $nip = preg_replace('/[^0-9]/', '', $nip);
        
        if (strlen($nip) !== 10) {
            return false;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += $weights[$i] * (int)$nip[$i];
        }

        $checksum = $sum % 11;
        
        return $checksum === (int)$nip[9];
    }
}
