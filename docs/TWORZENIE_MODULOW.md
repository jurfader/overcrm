# 📦 System Modułów CHICKENKING Planner

## Spis treści
1. [Wprowadzenie](#wprowadzenie)
2. [Szybki start](#szybki-start)
3. [Struktura modułu](#struktura-modułu)
4. [Manifest module.json](#manifest-modulejson)
5. [Tworzenie kontrolerów](#tworzenie-kontrolerów)
6. [Modele i migracje](#modele-i-migracje)
7. [Widoki Vue](#widoki-vue)
8. [Routing](#routing)
9. [Uprawnienia](#uprawnienia)
10. [Ustawienia modułu](#ustawienia-modułu)
11. [Menu nawigacji](#menu-nawigacji)
12. [Przykłady](#przykłady)

---

## Wprowadzenie

System modułów pozwala rozszerzać funkcjonalność CHICKENKING Planner bez modyfikacji kodu głównej aplikacji. Każdy moduł jest samodzielną jednostką zawierającą:

- Kontrolery i logikę biznesową
- Modele i migracje bazy danych
- Komponenty Vue (widoki)
- Własne uprawnienia
- Konfigurowalny ustawienia
- Elementy menu

## Szybki start

### Tworzenie nowego modułu

Najszybszy sposób na utworzenie modułu to użycie komendy Artisan:

```bash
# Podstawowy moduł
php artisan make:module NazwaModulu

# Z dodatkowymi opcjami
php artisan make:module Invoices \
    --display="Faktury" \
    --description="Moduł do zarządzania fakturami" \
    --icon="document-text" \
    --with-model=Invoice \
    --with-migration
```

### Aktywacja modułu

1. Przejdź do **Panel Admina** → **Moduły**
2. Znajdź swój moduł na liście
3. Kliknij **Aktywuj**

---

## Struktura modułu

```
modules/
└── NazwaModulu/
    ├── module.json                 # Manifest modułu (WYMAGANY)
    ├── NazwaModuluServiceProvider.php  # Service Provider
    ├── config/
    │   └── config.php              # Konfiguracja modułu
    ├── database/
    │   ├── migrations/             # Migracje bazy danych
    │   └── seeders/                # Seedery
    ├── routes/
    │   ├── web.php                 # Routy webowe
    │   └── api.php                 # Routy API
    ├── src/
    │   ├── Controllers/            # Kontrolery
    │   ├── Models/                 # Modele Eloquent
    │   ├── Services/               # Serwisy biznesowe
    │   ├── Events/                 # Eventy
    │   ├── Listeners/              # Listenery
    │   └── Middleware/             # Middleware
    └── resources/
        └── js/
            ├── Pages/              # Strony Vue
            └── Components/         # Komponenty Vue
```

---

## Manifest module.json

Plik `module.json` to serce każdego modułu. Definiuje metadane, zależności, uprawnienia i ustawienia.

```json
{
    "name": "invoices",
    "display_name": "Faktury",
    "description": "Moduł do zarządzania fakturami",
    "version": "1.0.0",
    "author": "CHICKENKING",
    "icon": "document-text",
    "is_core": false,
    "order": 10,
    
    "dependencies": [
        "clients"
    ],
    
    "permissions": {
        "invoices_view": "Podgląd faktur",
        "invoices_create": "Tworzenie faktur",
        "invoices_edit": "Edycja faktur",
        "invoices_delete": "Usuwanie faktur",
        "invoices_export": "Eksport faktur"
    },
    
    "settings": {
        "general": {
            "default_payment_days": {
                "type": "number",
                "label": "Domyślny termin płatności (dni)",
                "default": 14,
                "description": "Ile dni od wystawienia faktury"
            },
            "auto_numbering": {
                "type": "boolean",
                "label": "Automatyczna numeracja",
                "default": true
            },
            "number_format": {
                "type": "select",
                "label": "Format numeru",
                "default": "FV/{YEAR}/{NUMBER}",
                "options": {
                    "FV/{YEAR}/{NUMBER}": "FV/2026/001",
                    "FV/{MONTH}/{YEAR}/{NUMBER}": "FV/02/2026/001",
                    "{NUMBER}/{YEAR}": "001/2026"
                }
            }
        },
        "integration": {
            "fakturownia_api_key": {
                "type": "password",
                "label": "Klucz API Fakturownia",
                "default": "",
                "description": "Opcjonalna integracja z Fakturownia.pl"
            }
        }
    },
    
    "routes": {
        "web": "routes/web.php",
        "api": "routes/api.php"
    },
    
    "menu": [
        {
            "label": "Faktury",
            "route": "invoices.index",
            "icon": "document-text",
            "permission": "invoices_view",
            "children": [
                {
                    "label": "Lista faktur",
                    "route": "invoices.index",
                    "permission": "invoices_view"
                },
                {
                    "label": "Nowa faktura",
                    "route": "invoices.create",
                    "permission": "invoices_create"
                }
            ]
        }
    ]
}
```

### Opis pól manifestu

| Pole | Typ | Wymagane | Opis |
|------|-----|----------|------|
| `name` | string | ✅ | Unikalna nazwa (lowercase, bez spacji) |
| `display_name` | string | ✅ | Wyświetlana nazwa |
| `description` | string | ❌ | Opis modułu |
| `version` | string | ✅ | Wersja (semver) |
| `author` | string | ❌ | Autor modułu |
| `icon` | string | ❌ | Nazwa ikony (z Icons.vue) |
| `is_core` | boolean | ❌ | Czy moduł systemowy (nie można usunąć) |
| `order` | number | ❌ | Kolejność w menu |
| `dependencies` | array | ❌ | Lista wymaganych modułów |
| `permissions` | object | ❌ | Definicje uprawnień |
| `settings` | object | ❌ | Ustawienia modułu |
| `menu` | array | ❌ | Elementy menu |

---

## Tworzenie kontrolerów

### Podstawowy kontroler

```php
<?php

namespace Modules\Invoices\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Invoices\Models\Invoice;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $invoices = Invoice::with(['client', 'items'])
            ->when($request->search, fn($q, $search) => 
                $q->where('number', 'like', "%{$search}%")
            )
            ->latest()
            ->paginate(15);

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Invoices/Form', [
            'invoice' => null,
            'clients' => \App\Models\Client::active()->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after:issue_date',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.price' => 'required|numeric|min:0',
        ]);

        $invoice = Invoice::create($validated);
        $invoice->items()->createMany($validated['items']);
        $invoice->calculateTotal();

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Faktura została utworzona');
    }

    public function show(Invoice $invoice)
    {
        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice->load(['client', 'items']),
        ]);
    }

    public function edit(Invoice $invoice)
    {
        return Inertia::render('Invoices/Form', [
            'invoice' => $invoice->load('items'),
            'clients' => \App\Models\Client::active()->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Invoice $invoice)
    {
        // Walidacja i aktualizacja...
    }

    public function destroy(Invoice $invoice)
    {
        $invoice->delete();
        
        return redirect()
            ->route('invoices.index')
            ->with('success', 'Faktura została usunięta');
    }
}
```

---

## Modele i migracje

### Tworzenie modelu

```php
<?php

namespace Modules\Invoices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Client;

class Invoice extends Model
{
    protected $fillable = [
        'client_id',
        'number',
        'issue_date',
        'due_date',
        'status',
        'subtotal',
        'tax',
        'total',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    // Relacje
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    // Metody pomocnicze
    public function calculateTotal(): void
    {
        $this->subtotal = $this->items->sum(fn($item) => 
            $item->quantity * $item->price
        );
        $this->tax = $this->subtotal * 0.23; // 23% VAT
        $this->total = $this->subtotal + $this->tax;
        $this->save();
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isOverdue(): bool
    {
        return !$this->isPaid() && $this->due_date->isPast();
    }

    // Scopes
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeUnpaid($query)
    {
        return $query->where('status', '!=', 'paid');
    }

    public function scopeOverdue($query)
    {
        return $query->unpaid()->where('due_date', '<', now());
    }
}
```

### Tworzenie migracji

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->string('number')->unique();
            $table->date('issue_date');
            $table->date('due_date');
            $table->enum('status', ['draft', 'sent', 'paid', 'cancelled'])->default('draft');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['client_id', 'status']);
            $table->index('due_date');
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 10, 2);
            $table->string('unit')->default('szt.');
            $table->decimal('price', 12, 2);
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
```

---

## Widoki Vue

### Strona listy (Index.vue)

```vue
<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Icons from '@/Components/Icons.vue';
import Pagination from '@/Components/Pagination.vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    invoices: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');

// Debounced search
watch(search, debounce((value) => {
    router.get(route('invoices.index'), { search: value }, {
        preserveState: true,
        replace: true,
    });
}, 300));

function deleteInvoice(invoice) {
    if (confirm('Czy na pewno chcesz usunąć tę fakturę?')) {
        router.delete(route('invoices.destroy', invoice.id));
    }
}

const statusColors = {
    draft: 'bg-gray-100 text-gray-800',
    sent: 'bg-blue-100 text-blue-800',
    paid: 'bg-green-100 text-green-800',
    cancelled: 'bg-red-100 text-red-800',
};

const statusLabels = {
    draft: 'Szkic',
    sent: 'Wysłana',
    paid: 'Opłacona',
    cancelled: 'Anulowana',
};
</script>

<template>
    <Head title="Faktury" />

    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Faktury</h1>
                <p class="text-gray-500">Zarządzaj fakturami</p>
            </div>
            <Link :href="route('invoices.create')" class="btn-primary">
                <Icons name="plus" class="w-5 h-5 mr-2" />
                Nowa faktura
            </Link>
        </div>

        <!-- Filtrowanie -->
        <div class="bg-white rounded-lg shadow p-4">
            <input
                v-model="search"
                type="text"
                placeholder="Szukaj faktury..."
                class="form-input w-full max-w-sm"
            />
        </div>

        <!-- Tabela -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Numer</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Klient</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Data</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Kwota</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Akcje</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="invoice in invoices.data" :key="invoice.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap font-medium text-blue-600">
                            <Link :href="route('invoices.show', invoice.id)">
                                {{ invoice.number }}
                            </Link>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">{{ invoice.client?.name }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ new Date(invoice.issue_date).toLocaleDateString('pl-PL') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap font-semibold">
                            {{ parseFloat(invoice.total).toLocaleString('pl-PL', { style: 'currency', currency: 'PLN' }) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="['px-2 py-1 text-xs font-medium rounded-full', statusColors[invoice.status]]">
                                {{ statusLabels[invoice.status] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <Link :href="route('invoices.edit', invoice.id)" class="text-blue-600 hover:text-blue-900 mr-3">
                                Edytuj
                            </Link>
                            <button @click="deleteInvoice(invoice)" class="text-red-600 hover:text-red-900">
                                Usuń
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Paginacja -->
        <Pagination :links="invoices.links" />
    </div>
</template>
```

---

## Routing

### Plik routes/web.php

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Invoices\Controllers\InvoiceController;

Route::middleware(['auth', 'verified'])->group(function () {
    // Lista faktur
    Route::get('/', [InvoiceController::class, 'index'])
        ->name('index')
        ->middleware('permission:invoices_view');
    
    // Tworzenie
    Route::get('/create', [InvoiceController::class, 'create'])
        ->name('create')
        ->middleware('permission:invoices_create');
    
    Route::post('/', [InvoiceController::class, 'store'])
        ->name('store')
        ->middleware('permission:invoices_create');
    
    // Podgląd
    Route::get('/{invoice}', [InvoiceController::class, 'show'])
        ->name('show')
        ->middleware('permission:invoices_view');
    
    // Edycja
    Route::get('/{invoice}/edit', [InvoiceController::class, 'edit'])
        ->name('edit')
        ->middleware('permission:invoices_edit');
    
    Route::put('/{invoice}', [InvoiceController::class, 'update'])
        ->name('update')
        ->middleware('permission:invoices_edit');
    
    // Usuwanie
    Route::delete('/{invoice}', [InvoiceController::class, 'destroy'])
        ->name('destroy')
        ->middleware('permission:invoices_delete');
    
    // Dodatkowe akcje
    Route::post('/{invoice}/send', [InvoiceController::class, 'send'])
        ->name('send')
        ->middleware('permission:invoices_edit');
    
    Route::get('/{invoice}/pdf', [InvoiceController::class, 'pdf'])
        ->name('pdf')
        ->middleware('permission:invoices_view');
});
```

---

## Uprawnienia

### Definiowanie w module.json

```json
{
    "permissions": {
        "invoices_view": "Podgląd faktur",
        "invoices_create": "Tworzenie faktur",
        "invoices_edit": "Edycja faktur",
        "invoices_delete": "Usuwanie faktur",
        "invoices_export": "Eksport faktur"
    }
}
```

### Sprawdzanie w kontrolerze

```php
// Przez middleware
Route::get('/invoices', ...)->middleware('permission:invoices_view');

// W kontrolerze
public function index()
{
    $this->authorize('invoices_view');
    // lub
    if (!auth()->user()->hasPermission('invoices_view')) {
        abort(403);
    }
}
```

### Sprawdzanie w Vue

```vue
<template>
    <Link 
        v-if="$page.props.auth.user.permissions.includes('invoices_create')"
        :href="route('invoices.create')"
    >
        Nowa faktura
    </Link>
</template>
```

---

## Ustawienia modułu

### Definiowanie w module.json

```json
{
    "settings": {
        "general": {
            "setting_key": {
                "type": "string|number|boolean|select|password|textarea",
                "label": "Etykieta",
                "default": "wartość domyślna",
                "description": "Opis ustawienia",
                "options": {}, // dla typu select
                "public": false // czy widoczne publicznie
            }
        }
    }
}
```

### Pobieranie ustawień w kodzie

```php
use App\Models\Setting;

// Pobierz pojedyncze ustawienie
$paymentDays = Setting::get('invoices', 'default_payment_days', 14);

// Pobierz wszystkie ustawienia modułu
$settings = Setting::getModuleSettings('invoices');
```

---

## Menu nawigacji

### Definiowanie w module.json

```json
{
    "menu": [
        {
            "label": "Faktury",
            "route": "invoices.index",
            "icon": "document-text",
            "permission": "invoices_view",
            "order": 50,
            "children": [
                {
                    "label": "Lista faktur",
                    "route": "invoices.index"
                },
                {
                    "label": "Nowa faktura",
                    "route": "invoices.create",
                    "permission": "invoices_create"
                }
            ]
        }
    ]
}
```

---

## Przykłady modułów

### 1. Moduł Raportów

Prosty moduł generujący raporty:

```bash
php artisan make:module Reports --display="Raporty" --icon="chart-bar"
```

### 2. Moduł Powiadomień

```bash
php artisan make:module Notifications \
    --display="Powiadomienia" \
    --icon="bell" \
    --with-model=Notification \
    --with-migration
```

### 3. Moduł Integracji API

```bash
php artisan make:module ApiIntegrations \
    --display="Integracje API" \
    --description="Integracje z zewnętrznymi serwisami" \
    --icon="link"
```

---

## Cykl życia modułu

```
1. Utworzenie modułu (make:module)
       ↓
2. Konfiguracja (module.json)
       ↓
3. Implementacja (kontrolery, modele, widoki)
       ↓
4. Migracje (php artisan migrate)
       ↓
5. Aktywacja (Panel Admina)
       ↓
6. Użycie
       ↓
7. Aktualizacja (zmiana version w module.json)
       ↓
8. Dezaktywacja / Odinstalowanie
```

---

## Dobre praktyki

1. **Nazewnictwo** - używaj CamelCase dla nazw modułów (Invoices, UserReports)
2. **Zależności** - zawsze deklaruj zależności w `dependencies`
3. **Uprawnienia** - twórz granularne uprawnienia (view, create, edit, delete)
4. **Migracje** - używaj prefiksów tabel (`invoices_`, `reports_`)
5. **Ustawienia** - grupuj ustawienia logicznie (general, integration, notifications)
6. **Dokumentacja** - dodaj README.md do modułu

---

## Rozwiązywanie problemów

### Moduł nie ładuje się
```bash
php artisan module:discover
php artisan cache:clear
```

### Routy nie działają
```bash
php artisan route:cache
php artisan route:clear
```

### Widoki Vue nie są widoczne
```bash
npm run build
```

---

