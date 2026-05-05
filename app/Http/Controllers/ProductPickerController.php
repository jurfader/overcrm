<?php

namespace App\Http\Controllers;

use App\Contracts\ProductProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cienki endpoint dla picker'a produktów w ClientModal (zakładka Zamówienia).
 *
 * Deleguje do aktywnego ProductProvider (LocalProductProvider domyślnie,
 * ApiloProductProvider/BaselinkerProductProvider gdy admin przełączy w Settings → Integracje).
 *
 * Wynik zawsze jako JSON, ograniczony do 50 wyników żeby dropdown nie zawiesił przeglądarki.
 */
class ProductPickerController extends Controller
{
    public function __construct(protected ProductProvider $products) {}

    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->get('q', ''));

        if ($term === '') {
            // Brak zapytania = pierwsze 50 aktywnych (popularne / niedawne / alfabetycznie)
            $items = $this->products->search('', 50);
        } else {
            $items = $this->products->search($term, 50);
        }

        return response()->json([
            'products' => $items->values(),
            'provider' => [
                'key'   => $this->products->key(),
                'label' => $this->products->label(),
            ],
        ]);
    }
}
