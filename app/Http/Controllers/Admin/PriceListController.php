<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Services\FakturowniaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PriceListController extends Controller
{
    public function index()
    {
        $priceLists = PriceList::orderBy('name')->get([
            'id', 'name', 'slug', 'description', 'is_active', 'is_public',
            'sync_from_fakturownia', 'fakturownia_prefix', 'last_synced_at',
        ]);

        return Inertia::render('Admin/PriceLists/Index', [
            'priceLists' => $priceLists,
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/PriceLists/Edit', [
            'priceList' => null,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:price_lists,slug',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sync_from_fakturownia' => 'boolean',
            'fakturownia_prefix' => 'nullable|string|max:50',
            'html_content' => 'nullable|string',
            'html_file' => 'nullable|file|mimes:html,htm|max:10240',
        ]);

        if ($request->hasFile('html_file')) {
            $validated['html_content'] = file_get_contents($request->file('html_file')->getRealPath());
        }

        $validated['slug'] = $validated['slug'] ?: Str::slug($validated['name']);
        unset($validated['html_file']);

        PriceList::create($validated);

        return redirect()->route('admin.price-lists.index')
            ->with('success', 'Cennik został utworzony.');
    }

    public function edit(PriceList $priceList)
    {
        return Inertia::render('Admin/PriceLists/Edit', [
            'priceList' => $priceList->only([
                'id', 'name', 'slug', 'description', 'is_active', 'is_public',
                'sync_from_fakturownia', 'fakturownia_prefix', 'last_synced_at',
                // Nie wysyłamy html_content (może być duże) — dopiero przy save
            ]),
        ]);
    }

    public function update(Request $request, PriceList $priceList)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:price_lists,slug,' . $priceList->id,
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'sync_from_fakturownia' => 'boolean',
            'fakturownia_prefix' => 'nullable|string|max:50',
            'html_content' => 'nullable|string',
            'html_file' => 'nullable|file|mimes:html,htm|max:10240',
        ]);

        if ($request->hasFile('html_file')) {
            $validated['html_content'] = file_get_contents($request->file('html_file')->getRealPath());
        } elseif (!array_key_exists('html_content', $validated) || $validated['html_content'] === null) {
            // Jeśli nie przesłano html — zachowaj stary
            unset($validated['html_content']);
        }

        $validated['slug'] = $validated['slug'] ?: Str::slug($validated['name']);
        unset($validated['html_file']);

        $priceList->update($validated);

        return redirect()->route('admin.price-lists.index')
            ->with('success', 'Cennik został zaktualizowany.');
    }

    public function destroy(PriceList $priceList)
    {
        $priceList->delete();

        return redirect()->route('admin.price-lists.index')
            ->with('success', 'Cennik został usunięty.');
    }

    public function sync(PriceList $priceList, FakturowniaService $fakturownia)
    {
        if (!$priceList->sync_from_fakturownia) {
            return response()->json(['error' => 'Synchronizacja nie jest włączona dla tego cennika.'], 422);
        }

        if (empty($priceList->html_content)) {
            return response()->json(['error' => 'Cennik nie ma treści HTML.'], 422);
        }

        try {
            $products = $fakturownia->getProducts($priceList->fakturownia_prefix ?: null, forceRefresh: true);

            if (empty($products)) {
                return response()->json(['error' => 'Brak produktów w Fakturowni (sprawdź prefix i klucz API).'], 422);
            }

            // Mapa: znormalizowana nazwa/sku → produkt
            $productMap = [];
            foreach ($products as $p) {
                $normalizedName = $this->normalizeName($p['name'], $priceList->fakturownia_prefix);
                $productMap[$normalizedName] = $p;
                if (!empty($p['sku'])) {
                    $productMap[strtolower(trim($p['sku']))] = $p;
                }
            }

            $dom = new \DOMDocument('1.0', 'UTF-8');
            libxml_use_internal_errors(true);
            $dom->loadHTML(mb_convert_encoding($priceList->html_content, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();

            $xpath = new \DOMXPath($dom);
            $updated = 0;

            $cards = $xpath->query('//article[contains(@class,"card")]');
            foreach ($cards as $card) {
                $h2nodes = $xpath->query('.//h2', $card);
                if ($h2nodes->length === 0) continue;

                $productName = trim($h2nodes->item(0)->textContent);
                $normalizedCardName = $this->normalizeName($productName, $priceList->fakturownia_prefix);

                $dataSku = $card->hasAttribute('data-sku') ? strtolower(trim($card->getAttribute('data-sku'))) : null;

                $matched = null;
                if ($dataSku && isset($productMap[$dataSku])) {
                    $matched = $productMap[$dataSku];
                } elseif (isset($productMap[$normalizedCardName])) {
                    $matched = $productMap[$normalizedCardName];
                } else {
                    // Dopasowanie: substring lub wszystkie słowa z karty zawarte w nazwie Fakturowni
                    foreach ($productMap as $key => $prod) {
                        if ($normalizedCardName === '') continue;
                        if (str_contains($key, $normalizedCardName) || str_contains($normalizedCardName, $key)) {
                            $matched = $prod;
                            break;
                        }
                        // Sprawdź czy wszystkie słowa z nazwy karty występują w nazwie Fakturowni
                        $cardWords = explode(' ', $normalizedCardName);
                        $allWordsMatch = count($cardWords) >= 2;
                        foreach ($cardWords as $word) {
                            if (mb_strlen($word) < 2 || !str_contains($key, $word)) {
                                $allWordsMatch = false;
                                break;
                            }
                        }
                        if ($allWordsMatch) {
                            $matched = $prod;
                            break;
                        }
                    }
                }

                if (!$matched) continue;

                $this->updateMetric($xpath, $card, 'Cena brutto', number_format($matched['price'], 2, ',', ' '));
                $this->updateMetric($xpath, $card, 'Cena netto', number_format($matched['price_net'], 2, ',', ' '));
                if (!empty($matched['tax_rate'])) {
                    $this->updateMetric($xpath, $card, 'VAT', (int) $matched['tax_rate'] . '%');
                }
                // Wylicz cenę za sztukę na podstawie "Sztuk w paczce"
                $sztukNode = $xpath->query('.//div[@aria-label="Sztuk w paczce"]//span[contains(@class,"fw-semibold")]', $card);
                if ($sztukNode->length > 0) {
                    $sztuk = (int) $sztukNode->item(0)->textContent;
                    if ($sztuk > 0) {
                        $pricePerUnit = $matched['price_net'] / $sztuk;
                        $this->updateMetric($xpath, $card, 'Cena za sztukę', number_format($pricePerUnit, 2, ',', ' '));
                    }
                }
                $updated++;
            }

            // Aktualizuj datę cennika (element <time>)
            $now = now();
            $timeNodes = $xpath->query('//time[contains(@class,"text-muted")]');
            foreach ($timeNodes as $timeNode) {
                $timeNode->setAttribute('datetime', $now->format('Y-m-d'));
                $timeNode->textContent = 'Data: ' . $now->format('d.m.Y');
            }

            $html = $dom->saveHTML();

            $priceList->html_content = $html;
            $priceList->last_synced_at = $now;
            $priceList->save();

            return response()->json([
                'success' => true,
                'updated' => $updated,
                'total_products' => count($products),
                'last_synced_at' => $priceList->last_synced_at->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('PriceList sync error', ['id' => $priceList->id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Błąd synchronizacji: ' . $e->getMessage()], 500);
        }
    }

    private function updateMetric(\DOMXPath $xpath, \DOMNode $card, string $ariaLabel, string $value): void
    {
        $nodes = $xpath->query('.//div[@aria-label="' . $ariaLabel . '"]//span[contains(@class,"fw-semibold")]', $card);
        if ($nodes->length > 0) {
            $nodes->item(0)->textContent = $value;
        }
    }

    private function normalizeName(string $name, ?string $prefix): string
    {
        if ($prefix) {
            $name = preg_replace('/^' . preg_quote($prefix, '/') . '/i', '', $name);
        }
        // Usuń cudzysłowy, apostrofy i znaki specjalne
        $name = preg_replace('/[\x{201E}\x{201C}\x{201D}\x{201F}\x{00AB}\x{00BB}\x{2018}\x{2019}\x{0022}\x{0027}]/u', '', $name);
        // Lowercase (mb_ dla polskich liter)
        $name = mb_strtolower(trim($name), 'UTF-8');
        // Normalizuj spacje
        $name = preg_replace('/\s+/', ' ', $name);
        return $name;
    }
}
