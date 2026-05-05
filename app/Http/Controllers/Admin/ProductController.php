<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    protected array $units = ['szt', 'kg', 'l', 'godz', 'm', 'm2', 'm3', 'opak'];
    protected array $vatRates = [23, 8, 5, 0];

    public function index(Request $request): Response
    {
        $products = Product::query()
            ->search($request->get('q'))
            ->when($request->get('category'), fn($q, $c) => $q->where('category', $c))
            ->when($request->get('only_active'), fn($q) => $q->active())
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        $categories = Product::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->toArray();

        return Inertia::render('Admin/Products/Index', [
            'products'   => $products,
            'categories' => $categories,
            'units'      => $this->units,
            'vatRates'   => $this->vatRates,
            'filters'    => [
                'q'           => $request->get('q', ''),
                'category'    => $request->get('category', ''),
                'only_active' => (bool) $request->get('only_active', false),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        License::guard('Dodawanie produktu wymaga ważnej licencji');
        $data = $this->validateData($request);

        Product::create($data);
        return back()->with('success', 'Produkt dodany');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        License::guard('Edycja produktu wymaga ważnej licencji');
        $data = $this->validateData($request, $product);

        $product->update($data);
        return back()->with('success', 'Produkt zaktualizowany');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();
        return back()->with('success', 'Produkt usunięty (soft-delete)');
    }

    protected function validateData(Request $request, ?Product $existing = null): array
    {
        return $request->validate([
            'sku'         => ['nullable', 'string', 'max:60', 'unique:products,sku' . ($existing ? ",{$existing->id}" : '')],
            'name'        => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
            'category'    => 'nullable|string|max:80',
            'unit'        => ['required', 'string', 'in:' . implode(',', $this->units)],
            'price_net'   => 'required|numeric|min:0',
            'vat_rate'    => ['required', 'integer', 'in:' . implode(',', $this->vatRates)],
            'stock'       => 'nullable|numeric|min:0',
            'track_stock' => 'boolean',
            'active'      => 'boolean',
        ]);
    }
}
