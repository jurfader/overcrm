<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\PriceListController as AdminPriceListController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\ProductPickerController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\IntegrationLogController;
use App\Http\Controllers\Admin\IntegrationsController;
use App\Http\Controllers\Admin\ModuleController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserMailConfigController;
use App\Http\Controllers\VisitEmailController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Publiczny cennik (bez logowania)
Route::get('/cennik/{slug}', [PriceListController::class, 'show'])->name('price-lists.show');
Route::get('/cennik/{slug}/pdf', [PriceListController::class, 'pdf'])->name('price-lists.pdf');

// Strona główna – od razu przekierowanie do logowania lub dashboardu
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
})->name('welcome');

// Licencja — zalogowany user musi móc tu wejść NAWET gdy licencja wygasła (whitelisted w EnforceLicense)
Route::middleware(['auth'])->group(function () {
    Route::get('/license', [LicenseController::class, 'show'])->name('license.show');
    Route::post('/license/activate', [LicenseController::class, 'activate'])->name('license.activate');
    Route::post('/license/refresh', [LicenseController::class, 'refresh'])->name('license.refresh');

    // Support — zgłoszenia błędów (whitelisted, dostępne nawet gdy licencja invalid)
    Route::post('/support/ticket', [SupportController::class, 'submit'])->name('support.submit');
});

// Routing dla zalogowanych użytkowników
Route::middleware(['auth', 'verified', '2fa'])->group(function () {

    // Wersja buildu – do wykrywania nowego deployu (polling)
    Route::get('/build-version', function () {
        $manifestPath = public_path('build/manifest.json');
        if (!file_exists($manifestPath)) {
            return response()->json(['buildVersion' => 'dev']);
        }
        try {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            $appEntry = $manifest['resources/js/app.js'] ?? null;
            $version = $appEntry['file'] ?? (string) filemtime($manifestPath);
            return response()->json(['buildVersion' => $version]);
        } catch (\Throwable $e) {
            return response()->json(['buildVersion' => 'dev']);
        }
    })->name('build-version');

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/layout', [DashboardController::class, 'saveLayout'])->name('dashboard.save-layout');
    Route::delete('/dashboard/layout', [DashboardController::class, 'resetLayout'])->name('dashboard.reset-layout');

    // Changelog
    Route::get('/changelog', [ChangelogController::class, 'index'])->name('changelog.index');

    // Zamówienia — deleguje do aktywnego OrderProvider (LocalOrderProvider domyślnie,
    // moduły Apilo/BaseLinker zastępują przez ProviderRegistry).
    // ID jako string żeby external providers (Apilo IDs nie są integer-only) działali.
    Route::prefix('orders')->name('orders.')->group(function () {
        Route::post('/', [OrderController::class, 'store'])->name('store');
        Route::get('/{order}', [OrderController::class, 'show'])->name('show')->where('order', '[A-Za-z0-9_-]+');
        Route::get('/{order}/pdf', [OrderController::class, 'pdf'])->name('pdf')->where('order', '[A-Za-z0-9_-]+');
    });
    Route::get('/clients/{client}/orders', [OrderController::class, 'listByClient'])->name('clients.orders.list');

    // Picker produktów (z aktywnego ProductProvider) — używany w ClientModal/Zamówienia
    Route::get('/products/search', [ProductPickerController::class, 'search'])->name('products.search');

    // Cenniki (lista dla zalogowanych)
    Route::get('/cenniki', [PriceListController::class, 'index'])->name('price-lists.index');

    // Zadania (Tasks/Planner)
    Route::prefix('tasks')->name('tasks.')->group(function () {
        Route::get('/', [TaskController::class, 'index'])->name('index');
        Route::post('/bulk-action', [TaskController::class, 'bulkAction'])->name('bulk-action')->middleware('permission:tasks_manage');
        Route::get('/create', [TaskController::class, 'create'])->name('create')->middleware('permission:tasks_manage');
        Route::post('/', [TaskController::class, 'store'])->name('store')->middleware('permission:tasks_manage');
        Route::get('/{task}', [TaskController::class, 'show'])->name('show');
        Route::post('/{task}/comments', [TaskController::class, 'storeComment'])->name('comments.store');
        Route::delete('/{task}/comments/{comment}', [TaskController::class, 'destroyComment'])->name('comments.destroy');
        Route::get('/{task}/edit', [TaskController::class, 'edit'])->name('edit')->middleware('permission:tasks_manage');
        Route::put('/{task}', [TaskController::class, 'update'])->name('update')->middleware('permission:tasks_manage');
        Route::patch('/{task}/status', [TaskController::class, 'updateStatus'])->name('update-status')->middleware('permission:tasks_manage');
        Route::delete('/{task}', [TaskController::class, 'destroy'])->name('destroy')->middleware('permission:tasks_manage');
        Route::post('/{id}/restore', [TaskController::class, 'restore'])->name('restore')->middleware('permission:tasks_manage');
        Route::delete('/{id}/force', [TaskController::class, 'forceDelete'])->name('force-delete')->middleware('permission:tasks_manage');
    });

    // Klienci
    Route::prefix('clients')->name('clients.')->group(function () {
        Route::get('/', [ClientController::class, 'index'])->name('index');
        Route::post('/bulk-action', [ClientController::class, 'bulkAction'])->name('bulk-action')->middleware('permission:clients_manage');
        Route::get('/export', [ClientController::class, 'export'])->name('export');
        Route::post('/import', [ClientController::class, 'import'])->name('import')->middleware('permission:clients_manage');
        Route::get('/create', [ClientController::class, 'create'])->name('create')->middleware('permission:clients_manage');
        Route::post('/', [ClientController::class, 'store'])->name('store')->middleware('permission:clients_manage');
        Route::post('/quick', [ClientController::class, 'quickStore'])->name('quick-store')->middleware('permission:clients_manage');
        Route::get('/lookup-nip', [ClientController::class, 'lookupNip'])->name('lookup-nip');
        Route::get('/search', [ClientController::class, 'search'])->name('search');
        Route::get('/{client}/json', [ClientController::class, 'showJson'])->name('show-json');
        Route::get('/{client}', [ClientController::class, 'show'])->name('show');
        Route::get('/{client}/edit', [ClientController::class, 'edit'])->name('edit')->middleware('permission:clients_manage');
        Route::put('/{client}', [ClientController::class, 'update'])->name('update')->middleware('permission:clients_manage');
        Route::delete('/{client}', [ClientController::class, 'destroy'])->name('destroy')->middleware('permission:clients_manage');
    });

    // Użytkownicy (tylko admin i manager)
    Route::prefix('users')->name('users.')->middleware('role:admin,manager')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/create', [UserController::class, 'create'])->name('create')->middleware('role:admin');
        Route::post('/', [UserController::class, 'store'])->name('store')->middleware('role:admin');
        Route::get('/{user}', [UserController::class, 'show'])->name('show');
        Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit')->middleware('role:admin');
        Route::put('/{user}', [UserController::class, 'update'])->name('update')->middleware('role:admin');
        Route::post('/{user}/avatar', [UserController::class, 'updateAvatar'])->name('avatar')->middleware('role:admin');
        Route::delete('/{user}/avatar', [UserController::class, 'deleteAvatar'])->name('avatar.delete')->middleware('role:admin');
        Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy')->middleware('role:admin');
    });

    // Statusy (tylko admin)
    Route::prefix('statuses')->name('statuses.')->middleware('role:admin')->group(function () {
        Route::get('/', [StatusController::class, 'index'])->name('index');
        Route::get('/create', [StatusController::class, 'create'])->name('create');
        Route::post('/', [StatusController::class, 'store'])->name('store');
        Route::get('/{status}/edit', [StatusController::class, 'edit'])->name('edit');
        Route::put('/{status}', [StatusController::class, 'update'])->name('update');
        Route::delete('/{status}', [StatusController::class, 'destroy'])->name('destroy');
        Route::post('/reorder', [StatusController::class, 'reorder'])->name('reorder');
    });

    // InPost mapa – trasa główna (moduł może nie ładować tras na produkcji)
    Route::get('/inpost/map', function () {
        $token = \App\Models\Setting::get('geowidget_token', null, 'inpost') ?: config('services.inpost.geowidget_token', '');
        if (!$token) {
            abort(404, 'Geowidget nie skonfigurowany. Ustaw token w Admin → Moduły → InPost.');
        }
        $viewPath = base_path('modules/Inpost/resources/views/map.blade.php');
        return file_exists($viewPath)
            ? view()->file($viewPath, ['token' => $token])
            : abort(404, 'Moduł InPost nie znaleziony.');
    })->name('inpost.map');

    // Kalendarz klientów
    Route::prefix('calendar')->name('calendar.')->group(function () {
        Route::get('/', [CalendarController::class, 'index'])->name('index');
        Route::post('/', [CalendarController::class, 'store'])->name('store');
        Route::get('/visits-search', [CalendarController::class, 'visitsSearch'])->name('visits-search');
        Route::get('/visit-context/{visit}', [CalendarController::class, 'visitContext'])->name('visit-context');
        Route::get('/products', [CalendarController::class, 'getProducts'])->name('products');
        Route::get('/lookup-nip', [CalendarController::class, 'lookupNip'])->name('lookup-nip');
        Route::put('/client/{client}', [CalendarController::class, 'updateClient'])->name('update-client');
        Route::post('/restore/{id}', [CalendarController::class, 'restore'])->name('restore');
        Route::delete('/force/{id}', [CalendarController::class, 'forceDelete'])->name('force-delete');
        Route::get('/{visit}', [CalendarController::class, 'show'])->name('show');
        Route::put('/{visit}', [CalendarController::class, 'update'])->name('update');
        Route::delete('/{visit}', [CalendarController::class, 'destroy'])->name('destroy');
        Route::post('/{visit}/send-email', [VisitEmailController::class, 'send'])->name('send-email');
        Route::post('/{visit}/preview-email', [VisitEmailController::class, 'preview'])->name('preview-email');
    });

    // Ustawienia użytkownika - konfiguracja SMTP
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::put('/email-footer', [UserMailConfigController::class, 'updateEmailFooter'])->name('email-footer.update');
        Route::prefix('mail')->name('mail.')->group(function () {
            Route::get('/', [UserMailConfigController::class, 'index'])->name('index');
            Route::post('/', [UserMailConfigController::class, 'store'])->name('store');
            Route::put('/{mailConfig}', [UserMailConfigController::class, 'update'])->name('update');
            Route::delete('/{mailConfig}', [UserMailConfigController::class, 'destroy'])->name('destroy');
            Route::post('/{mailConfig}/default', [UserMailConfigController::class, 'setDefault'])->name('default');
            Route::post('/{mailConfig}/test', [UserMailConfigController::class, 'test'])->name('test');
        });
    });

    // Panel administracyjny (tylko admin)
    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        // Dashboard admina
        Route::get('/', [AdminController::class, 'index'])->name('dashboard');
        
        // Zarządzanie modułami — listing/install/sklep w Marketplace,
        // tu zostaje tylko strona konfiguracji per-modul (show/saveConfig)
        // + activate/deactivate/uninstall (wywolywane z marketplace UI).
        // /admin/modules redirect do marketplace dla starych bookmarkow.
        Route::get('/modules', fn () => redirect()->route('admin.marketplace.index'))->name('modules.index');
        Route::prefix('modules')->name('modules.')->group(function () {
            Route::get('/{module}', [ModuleController::class, 'show'])->name('show');
            Route::post('/{module}/activate', [ModuleController::class, 'activate'])->name('activate');
            Route::post('/{module}/deactivate', [ModuleController::class, 'deactivate'])->name('deactivate');
            Route::delete('/{module}', [ModuleController::class, 'uninstall'])->name('uninstall');
            Route::post('/{module}/config', [ModuleController::class, 'saveConfig'])->name('config');
        });

        Route::prefix('marketplace')->name('marketplace.')->group(function () {
            Route::get('/',        [App\Http\Controllers\Admin\MarketplaceController::class, 'index'])->name('index');
            Route::post('/install', [App\Http\Controllers\Admin\MarketplaceController::class, 'install'])->name('install');
        });
        
        // Branding — embedded w Settings → Wygląd. Tylko endpointy zapisu/uploadu, bez GET.
        Route::prefix('branding')->name('branding.')->group(function () {
            Route::post('/', [BrandingController::class, 'update'])->name('update');
            Route::post('/upload', [BrandingController::class, 'uploadAsset'])->name('upload');
            Route::delete('/asset', [BrandingController::class, 'removeAsset'])->name('remove-asset');
        });

        // Ustawienia systemowe
        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [SettingController::class, 'index'])->name('index');
            Route::post('/', [SettingController::class, 'update'])->name('update');
            Route::post('/add', [SettingController::class, 'store'])->name('store');
            Route::post('/upload-logo', [SettingController::class, 'uploadLogo'])->name('upload-logo');
            Route::delete('/{setting}', [SettingController::class, 'destroy'])->name('destroy');
        });

        // Logi integracji
        Route::get('/integration-logs', [IntegrationLogController::class, 'index'])->name('integration-logs');

        // Provider switcher (Settings → Integracje)
        Route::post('/integrations', [IntegrationsController::class, 'update'])->name('integrations.update');

        // Magazyn produktów (CORE)
        Route::prefix('products')->name('products.')->group(function () {
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::post('/', [ProductController::class, 'store'])->name('store');
            Route::put('/{product}', [ProductController::class, 'update'])->name('update');
            Route::delete('/{product}', [ProductController::class, 'destroy'])->name('destroy');
        });

        // Zamówienia — admin lista wszystkich (różne od /orders/* per-klient z modala)
        Route::prefix('orders')->name('orders.')->group(function () {
            Route::get('/', [AdminOrderController::class, 'index'])->name('index');
            Route::patch('/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('update-status');
            Route::delete('/{order}', [AdminOrderController::class, 'destroy'])->name('destroy');
        });

        // Cenniki (admin)
        Route::prefix('price-lists')->name('price-lists.')->group(function () {
            Route::get('/', [AdminPriceListController::class, 'index'])->name('index');
            Route::get('/create', [AdminPriceListController::class, 'create'])->name('create');
            Route::post('/', [AdminPriceListController::class, 'store'])->name('store');
            Route::get('/{priceList}/edit', [AdminPriceListController::class, 'edit'])->name('edit');
            Route::put('/{priceList}', [AdminPriceListController::class, 'update'])->name('update');
            Route::delete('/{priceList}', [AdminPriceListController::class, 'destroy'])->name('destroy');
            Route::post('/{priceList}/sync', [AdminPriceListController::class, 'sync'])->name('sync');
        });

        // Szablony email
        Route::prefix('email-templates')->name('email-templates.')->group(function () {
            Route::get('/', [EmailTemplateController::class, 'index'])->name('index');
            Route::get('/create', [EmailTemplateController::class, 'create'])->name('create');
            Route::post('/', [EmailTemplateController::class, 'store'])->name('store');
            Route::get('/{emailTemplate}/edit', [EmailTemplateController::class, 'edit'])->name('edit');
            Route::put('/{emailTemplate}', [EmailTemplateController::class, 'update'])->name('update');
            Route::delete('/{emailTemplate}', [EmailTemplateController::class, 'destroy'])->name('destroy');
            Route::post('/{emailTemplate}/preview', [EmailTemplateController::class, 'preview'])->name('preview');
            Route::post('/{emailTemplate}/duplicate', [EmailTemplateController::class, 'duplicate'])->name('duplicate');
        });
    });
});

require __DIR__.'/auth.php';
