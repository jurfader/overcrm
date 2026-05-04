<?php

namespace App\Http\Controllers;

use App\Models\PriceList;
use Inertia\Inertia;
use Spatie\Browsershot\Browsershot;

class PriceListController extends Controller
{
    /**
     * Lista cenników (widok autoryzowany — sidebar)
     */
    public function index()
    {
        $priceLists = PriceList::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'is_public', 'sync_from_fakturownia', 'last_synced_at']);

        return Inertia::render('PriceList/Index', [
            'priceLists' => $priceLists,
        ]);
    }

    /**
     * Publiczny widok cennika — serwuje surowy HTML, bez auth
     */
    public function show(string $slug)
    {
        $priceList = PriceList::where('slug', $slug)
            ->where('is_public', true)
            ->where('is_active', true)
            ->firstOrFail();

        return response($priceList->html_content ?? '')
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Pobierz cennik jako PDF
     */
    public function pdf(string $slug)
    {
        $priceList = PriceList::where('slug', $slug)
            ->where('is_public', true)
            ->where('is_active', true)
            ->firstOrFail();

        // Dodaj CSS dla druku — zapobiega łamaniu kart między stronami
        $printCss = '<style>article.card { break-inside: avoid !important; page-break-inside: avoid !important; margin-bottom: 6px !important; } .btn-buy { print-color-adjust: exact !important; -webkit-print-color-adjust: exact !important; }</style>';
        $html = str_replace('</head>', $printCss . '</head>', $priceList->html_content ?? '');

        $rawPath = storage_path('app/temp/cennik-' . $priceList->slug . '-raw-' . time() . '.pdf');
        $compressedPath = storage_path('app/temp/cennik-' . $priceList->slug . '-' . time() . '.pdf');
        if (!is_dir(dirname($rawPath))) {
            mkdir(dirname($rawPath), 0755, true);
        }

        Browsershot::html($html)
            ->noSandbox()
            ->format('A4')
            ->margins(8, 8, 8, 8)
            ->showBackground()
            ->windowSize(1200, 800)
            ->waitUntilNetworkIdle()
            ->save($rawPath);

        // Kompresja Ghostscriptem
        exec(sprintf(
            'gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook -dNOPAUSE -dBATCH -dQUIET -sOutputFile=%s %s 2>&1',
            escapeshellarg($compressedPath),
            escapeshellarg($rawPath)
        ), $out, $code);
        @unlink($rawPath);

        $finalPath = ($code === 0 && file_exists($compressedPath)) ? $compressedPath : $rawPath;
        $pdfContent = file_get_contents($finalPath);
        @unlink($finalPath);

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $priceList->slug . '.pdf"');
    }
}
