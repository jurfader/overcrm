<?php

namespace Modules\Leads\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Leads\Models\Lead;
use Modules\Leads\Models\LeadStatus;
use Modules\Leads\Services\OpenStreetMapScraperService;
use Modules\Leads\Services\LeadService;
use Modules\Leads\Services\PolishRegions;
use Modules\Leads\Services\PyszneScraperService;

class LeadSearchController extends Controller
{
    public function index()
    {
        return Inertia::render('Leads/Search', [
            'regions' => PolishRegions::all(),
        ]);
    }

    /**
     * Szukaj leadów w wybranym mieście. Scrapuje Pyszne.pl + OpenStreetMap.
     */
    public function search(Request $request)
    {
        $request->validate([
            'city' => 'nullable|string|max:100',
            'voivodeship' => 'nullable|string|max:50',
            'types' => 'nullable|array',
            'types.*' => 'string|max:50',
            'sources' => 'nullable|array',
            'sources.*' => 'in:pyszne,openstreetmap',
            'limit' => 'nullable|integer|min:10|max:500',
        ]);

        set_time_limit(600);

        $sources = $request->sources ?? ['pyszne', 'openstreetmap'];
        $limit = $request->limit ?? 200;

        // Zbierz listę miast do przeszukania
        $cities = [];
        if ($request->filled('city')) {
            $cities = [$request->city];
        } elseif ($request->filled('voivodeship')) {
            $cities = PolishRegions::getCities($request->voivodeship);
        } else {
            return response()->json(['error' => 'Wybierz województwo lub miasto'], 422);
        }

        $rawResults = [];
        $debugLog = [];
        $cityCount = count($cities);
        $perCity = max(10, intdiv($limit * 3, $cityCount)); // nadmiar na deduplikację

        foreach ($cities as $city) {
            if (in_array('pyszne', $sources)) {
                try {
                    $pyszne = new PyszneScraperService();
                    $pyszneResults = $pyszne->searchCity($city, $perCity);
                    $debugLog[] = "Pyszne.pl [{$city}]: " . count($pyszneResults) . " wyników";
                    $rawResults = array_merge($rawResults, $pyszneResults);
                } catch (\Throwable $e) {
                    $debugLog[] = "Pyszne.pl [{$city}]: BŁĄD — {$e->getMessage()}";
                }
            }

            if (in_array('openstreetmap', $sources)) {
                try {
                    $osm = new OpenStreetMapScraperService();
                    $osmResults = $osm->searchCity($city, $perCity);
                    $debugLog[] = "OpenStreetMap [{$city}]: " . count($osmResults) . " wyników";
                    $rawResults = array_merge($rawResults, $osmResults);
                } catch (\Throwable $e) {
                    $debugLog[] = "OpenStreetMap [{$city}]: BŁĄD — {$e->getMessage()}";
                }
            }
        }

        // Deduplikacja po nazwie
        $seen = [];
        $unique = [];
        foreach ($rawResults as $r) {
            $key = mb_strtolower(trim($r['name']));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $r;
        }

        $unique = array_slice($unique, 0, $limit);

        return response()->json([
            'success' => true,
            'results' => $unique,
            'total_scraped' => count($rawResults),
            'total_unique' => count($unique),
            'cities_searched' => $cities,
            'debug' => $debugLog,
        ]);
    }

    /**
     * Importuj wybrane wyniki jako leady.
     */
    public function import(Request $request, LeadService $leadService)
    {
        $request->validate([
            'leads' => 'required|array|min:1',
            'leads.*.name' => 'required|string',
            'leads.*.company_name' => 'nullable|string',
            'leads.*.phone' => 'nullable|string',
            'leads.*.email' => 'nullable|string',
            'leads.*.address' => 'nullable|string',
            'leads.*.city' => 'nullable|string',
            'leads.*.website' => 'nullable|string',
            'leads.*.source' => 'nullable|string',
            'leads.*.ai_reason' => 'nullable|string',
        ]);

        $imported = 0;
        foreach ($request->leads as $data) {
            try {
                $leadService->createLead([
                    'name' => $data['name'],
                    'company_name' => $data['company_name'] ?? $data['name'],
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'] ?? null,
                    'address' => $data['address'] ?? null,
                    'city' => $data['city'] ?? null,
                    'website' => $data['website'] ?? null,
                    'source' => $data['source'] ?? 'google_maps',
                    'notes' => $data['ai_reason'] ?? null,
                    'metadata' => ['ai_score' => $data['ai_score'] ?? null],
                ], auth()->id());
                $imported++;
            } catch (\Throwable $e) {
                // Pomiń duplikaty
            }
        }

        return response()->json([
            'success' => true,
            'imported' => $imported,
            'message' => "Zaimportowano {$imported} leadów.",
        ]);
    }
}
