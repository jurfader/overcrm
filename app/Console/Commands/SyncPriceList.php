<?php

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Services\FakturowniaService;
use Illuminate\Console\Command;

class SyncPriceList extends Command
{
    protected $signature = 'pricelist:sync {id : ID cennika do synchronizacji}';
    protected $description = 'Synchronizuje ceny w cenniku z produktami Fakturowni';

    public function handle(FakturowniaService $fakturownia): int
    {
        $priceList = PriceList::find($this->argument('id'));

        if (!$priceList) {
            $this->error('Cennik nie znaleziony.');
            return self::FAILURE;
        }

        if (!$priceList->sync_from_fakturownia) {
            $this->error('Ten cennik nie ma włączonej synchronizacji z Fakturownią.');
            return self::FAILURE;
        }

        if (empty($priceList->html_content)) {
            $this->error('Cennik nie ma treści HTML.');
            return self::FAILURE;
        }

        $this->info("Pobieranie produktów z Fakturowni (prefix: {$priceList->fakturownia_prefix})...");

        $products = $fakturownia->getProducts($priceList->fakturownia_prefix ?: null, forceRefresh: true);

        if (empty($products)) {
            $this->warn('Brak produktów do synchronizacji.');
            return self::SUCCESS;
        }

        $this->info('Znaleziono ' . count($products) . ' produktów.');

        // Zbuduj mapę: znormalizowana nazwa → produkt
        $productMap = [];
        foreach ($products as $p) {
            $normalizedName = $this->normalizeName($p['name'], $priceList->fakturownia_prefix);
            $productMap[$normalizedName] = $p;

            // Również po SKU (jeśli nie pusty)
            if (!empty($p['sku'])) {
                $productMap[strtolower(trim($p['sku']))] = $p;
            }
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $priceList->html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $updated = 0;
        $skipped = 0;

        // Znajdź wszystkie karty produktów (article.card lub div z h2)
        $cards = $xpath->query('//article[contains(@class,"card")]');

        foreach ($cards as $card) {
            // Nazwa produktu z h2
            $h2nodes = $xpath->query('.//h2', $card);
            if ($h2nodes->length === 0) {
                $skipped++;
                continue;
            }

            $productName = trim($h2nodes->item(0)->textContent);
            $normalizedCardName = $this->normalizeName($productName, $priceList->fakturownia_prefix);

            // Sprawdź data-sku na karcie
            $dataSku = null;
            if ($card->hasAttribute('data-sku')) {
                $dataSku = strtolower(trim($card->getAttribute('data-sku')));
            }

            $matched = null;
            if ($dataSku && isset($productMap[$dataSku])) {
                $matched = $productMap[$dataSku];
            } elseif (isset($productMap[$normalizedCardName])) {
                $matched = $productMap[$normalizedCardName];
            } else {
                // Próba częściowego dopasowania
                foreach ($productMap as $key => $prod) {
                    if (str_contains($key, $normalizedCardName) || str_contains($normalizedCardName, $key)) {
                        $matched = $prod;
                        break;
                    }
                }
            }

            if (!$matched) {
                $skipped++;
                continue;
            }

            // Zaktualizuj Cena brutto
            $this->updateMetric($xpath, $card, 'Cena brutto', number_format($matched['price'], 2, ',', ' '));
            // Zaktualizuj Cena netto
            $this->updateMetric($xpath, $card, 'Cena netto', number_format($matched['price_net'], 2, ',', ' '));
            // Zaktualizuj VAT
            if (!empty($matched['tax_rate'])) {
                $this->updateMetric($xpath, $card, 'VAT', (int) $matched['tax_rate'] . '%');
            }

            $updated++;
        }

        // Zapisz zaktualizowany HTML (bez sztucznego XML preamble)
        $html = $dom->saveHTML();
        $html = preg_replace('/<\?xml[^>]*\?>\n?/', '', $html);

        $priceList->html_content = $html;
        $priceList->last_synced_at = now();
        $priceList->save();

        $this->info("Zaktualizowano: {$updated} produktów, pominięto: {$skipped}.");

        return self::SUCCESS;
    }

    private function updateMetric(\DOMXPath $xpath, \DOMNode $card, string $ariaLabel, string $value): void
    {
        // Szukaj: .metric[aria-label="..."] .fw-semibold lub span.fw-semibold wewnątrz .metric z aria-label
        $metrics = $xpath->query(
            './/div[@aria-label="' . $ariaLabel . '"]//span[contains(@class,"fw-semibold")]',
            $card
        );

        if ($metrics->length > 0) {
            $metrics->item(0)->textContent = $value;
        }
    }

    private function normalizeName(string $name, ?string $prefix): string
    {
        // Usuń prefix (np. TH_)
        if ($prefix) {
            $name = preg_replace('/^' . preg_quote($prefix, '/') . '/i', '', $name);
        }
        // Lowercase, usuń zbędne spacje
        $name = strtolower(trim($name));
        $name = preg_replace('/\s+/', ' ', $name);
        return $name;
    }
}
