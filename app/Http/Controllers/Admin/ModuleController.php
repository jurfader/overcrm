<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Setting;
use App\Services\ModuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class ModuleController extends Controller
{
    protected ModuleService $moduleService;

    public function __construct(ModuleService $moduleService)
    {
        $this->moduleService = $moduleService;
    }

    /**
     * Lista wszystkich modułów
     */
    /**
     * Szczegóły modułu — strona konfiguracji per-modul. Listing modulow
     * przeniesiony do MarketplaceController (/admin/marketplace).
     */
    public function show(Module $module)
    {
        $module->load('logs.user');

        return Inertia::render('Admin/Modules/Show', [
            'module' => $module,
            'settings' => Setting::where('module', $module->name)
                ->orderBy('group')
                ->orderBy('order')
                ->get()
                ->groupBy('group'),
            'logs' => $module->logs()->with('user')->latest()->take(20)->get(),
        ]);
    }

    /**
     * Aktywuj moduł
     */
    public function activate(Module $module)
    {
        $result = $this->moduleService->activate($module);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Dezaktywuj moduł
     */
    public function deactivate(Module $module)
    {
        $result = $this->moduleService->deactivate($module);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Odinstaluj moduł
     */
    public function uninstall(Module $module)
    {
        $result = $this->moduleService->uninstall($module);

        if ($result['success']) {
            return redirect()->route('admin.marketplace.index')->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Zapisz konfigurację modułu
     */
    public function saveConfig(Request $request, Module $module)
    {
        $settings = $request->input('settings', []);

        foreach ($settings as $key => $value) {
            Setting::where('module', $module->name)
                ->where('key', $key)
                ->update(['value' => is_array($value) ? json_encode($value) : $value]);

            Cache::forget("setting.{$module->name}.{$key}");
        }

        $module->logAction('configured', ['settings' => array_keys($settings)]);

        return back()->with('success', 'Konfiguracja została zapisana');
    }
}
