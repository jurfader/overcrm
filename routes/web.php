<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AiTrainingController;
use App\Http\Controllers\Admin\PriceListController as AdminPriceListController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\BattleshipController;
use App\Http\Controllers\WarController;
use App\Http\Controllers\GbaController;
use App\Http\Controllers\MultiplayerGameController;
use App\Http\Controllers\Admin\DailyReportController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\IntegrationLogController;
use App\Http\Controllers\Admin\ModuleController;
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

// Publiczne endpointy gier (iframe, bez sesji – ustawienia i leaderboard)
Route::prefix('games')->name('games.')->group(function () {
    Route::get('/{id}/settings', [GameController::class, 'getSettings'])->name('settings.public');
    Route::get('/{id}/leaderboard', [GameController::class, 'leaderboard'])->name('leaderboard.public');
});

// ROM GBA z podpisem (dla gba.ninja – dostęp bez auth, ważny 10 min)
Route::get('/games/gba/roms/{filename}/signed', [GbaController::class, 'serveRomSigned'])
    ->middleware('signed')
    ->name('games.gba.roms.signed');

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

    // Changelog
    Route::get('/changelog', [ChangelogController::class, 'index'])->name('changelog.index');

    // Cenniki (lista dla zalogowanych)
    Route::get('/cenniki', [PriceListController::class, 'index'])->name('price-lists.index');
    Route::get('/dashboard/call-reminder/{client}', [DashboardController::class, 'callReminder'])->name('dashboard.call-reminder');

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
        Route::get('/apilo-options', [CalendarController::class, 'getApiloOptions'])->name('apilo-options');
        Route::get('/lookup-nip', [CalendarController::class, 'lookupNip'])->name('lookup-nip');
        Route::get('/invoices-by-nip', [CalendarController::class, 'invoicesByNip'])->name('invoices-by-nip');
        Route::get('/invoice/{id}', [CalendarController::class, 'invoiceDetail'])->name('invoice-detail');
        Route::get('/invoice/{id}/pdf', [CalendarController::class, 'invoicePdf'])->name('invoice-pdf');
        Route::get('/orders/{orderId}/tracking', [CalendarController::class, 'orderTracking'])->name('order-tracking');
        Route::put('/client/{client}', [CalendarController::class, 'updateClient'])->name('update-client');
        Route::post('/analyze-profile', [CalendarController::class, 'analyzeProfile'])->name('analyze-profile');
        Route::post('/generate-summary', [CalendarController::class, 'generateSummary'])->name('generate-summary');
        Route::post('/restore/{id}', [CalendarController::class, 'restore'])->name('restore');
        Route::delete('/force/{id}', [CalendarController::class, 'forceDelete'])->name('force-delete');
        Route::get('/{visit}', [CalendarController::class, 'show'])->name('show');
        Route::put('/{visit}', [CalendarController::class, 'update'])->name('update');
        Route::delete('/{visit}', [CalendarController::class, 'destroy'])->name('destroy');
        Route::post('/{visit}/create-order', [CalendarController::class, 'createApiloOrder'])->name('create-order');
        Route::post('/{visit}/send-email', [VisitEmailController::class, 'send'])->name('send-email');
        Route::post('/{visit}/preview-email', [VisitEmailController::class, 'preview'])->name('preview-email');
    });

    // Gry (ukryty panel – sekwencja „bojarchuj”)
    Route::prefix('games')->name('games.')->group(function () {
        Route::get('/', [GameController::class, 'index'])->name('index');
        Route::post('/', [GameController::class, 'store'])->name('store');
        Route::post('/scores', [GameController::class, 'storeScore'])->name('scores.store');
        Route::put('/{id}/settings', [GameController::class, 'updateSettings'])->name('settings.update');
        Route::delete('/{id}', [GameController::class, 'destroy'])->name('destroy');

        Route::prefix('multiplayer')->name('multiplayer.')->group(function () {
            Route::post('/rooms', [MultiplayerGameController::class, 'createRoom'])->name('rooms.create');
            Route::get('/rooms/{code}', [MultiplayerGameController::class, 'getRoom'])->name('rooms.show');
            Route::post('/rooms/{code}/join', [MultiplayerGameController::class, 'joinRoom'])->name('rooms.join');
            Route::post('/rooms/{code}/move', [MultiplayerGameController::class, 'makeMove'])->name('rooms.move');
        });

        Route::prefix('battleship')->name('battleship.')->group(function () {
            Route::post('/rooms', [BattleshipController::class, 'createRoom'])->name('rooms.create');
            Route::get('/rooms/{code}', [BattleshipController::class, 'getRoom'])->name('rooms.show');
            Route::post('/rooms/{code}/join', [BattleshipController::class, 'joinRoom'])->name('rooms.join');
            Route::post('/rooms/{code}/shot', [BattleshipController::class, 'makeShot'])->name('rooms.shot');
        });

        Route::prefix('war')->name('war.')->group(function () {
            Route::post('/rooms', [WarController::class, 'createRoom'])->name('rooms.create');
            Route::get('/rooms/{code}', [WarController::class, 'getRoom'])->name('rooms.show');
            Route::post('/rooms/{code}/join', [WarController::class, 'joinRoom'])->name('rooms.join');
            Route::post('/rooms/{code}/play', [WarController::class, 'play'])->name('rooms.play');
        });

        Route::prefix('gba')->name('gba.')->group(function () {
            Route::get('/roms', [GbaController::class, 'listRoms'])->name('roms.list');
            Route::get('/roms/{filename}', [GbaController::class, 'serveRom'])->name('roms.serve');
            Route::get('/saves/{romKey}', [GbaController::class, 'getSaveState'])->name('saves.get');
            Route::post('/saves/{romKey}', [GbaController::class, 'saveSaveState'])->name('saves.store');
            Route::get('/play/{romKey}', [GbaController::class, 'play'])->name('play');
        });

        Route::get('/{id}/{path?}', [GameController::class, 'serve'])->where('path', '.*')->name('serve');
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
        
        // Zarządzanie modułami
        Route::prefix('modules')->name('modules.')->group(function () {
            Route::get('/', [ModuleController::class, 'index'])->name('index');
            Route::post('/install', [ModuleController::class, 'install'])->name('install');
            Route::post('/generate', [ModuleController::class, 'generate'])->name('generate');
            Route::get('/{module}', [ModuleController::class, 'show'])->name('show');
            Route::post('/{module}/activate', [ModuleController::class, 'activate'])->name('activate');
            Route::post('/{module}/deactivate', [ModuleController::class, 'deactivate'])->name('deactivate');
            Route::delete('/{module}', [ModuleController::class, 'uninstall'])->name('uninstall');
            Route::post('/{module}/config', [ModuleController::class, 'saveConfig'])->name('config');
        });
        
        // Ustawienia systemowe
        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [SettingController::class, 'index'])->name('index');
            Route::post('/', [SettingController::class, 'update'])->name('update');
            Route::post('/add', [SettingController::class, 'store'])->name('store');
            Route::post('/upload-logo', [SettingController::class, 'uploadLogo'])->name('upload-logo');
            Route::post('/test-fakturownia', [SettingController::class, 'testFakturownia'])->name('test-fakturownia');
            Route::post('/test-apilo', [SettingController::class, 'testApilo'])->name('test-apilo');
            Route::post('/authorize-apilo', [SettingController::class, 'authorizeApilo'])->name('authorize-apilo');
            Route::delete('/{setting}', [SettingController::class, 'destroy'])->name('destroy');
        });

        // Logi integracji
        Route::get('/integration-logs', [IntegrationLogController::class, 'index'])->name('integration-logs');

        // Raport dzienny pracy użytkowników
        Route::get('/daily-report', [DailyReportController::class, 'index'])->name('daily-report');

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

        // Uczenie AI (pamięć analizy rozmów)
        Route::prefix('ai-training')->name('ai-training.')->group(function () {
            Route::get('/', [AiTrainingController::class, 'index'])->name('index');
            Route::post('/chat', [AiTrainingController::class, 'chat'])->name('chat');
            Route::get('/messages', [AiTrainingController::class, 'messages'])->name('messages');
            Route::post('/messages/clear', [AiTrainingController::class, 'clearMessages'])->name('messages.clear');
            Route::get('/memory', [AiTrainingController::class, 'getMemory'])->name('memory');
            Route::post('/reset', [AiTrainingController::class, 'resetMemory'])->name('reset');
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
