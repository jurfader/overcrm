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
    public function index()
    {
        // Sprawdź czy tabela istnieje
        if (!\Illuminate\Support\Facades\Schema::hasTable('modules')) {
            return Inertia::render('Admin/Modules/Index', [
                'modules' => [],
                'needsMigration' => true,
            ]);
        }

        // Odkryj nowe moduły
        $this->moduleService->discoverModules();

        $modules = Module::orderBy('order')
            ->orderBy('display_name')
            ->get()
            ->map(function ($module) {
                return [
                    'id' => $module->id,
                    'name' => $module->name,
                    'display_name' => $module->display_name,
                    'description' => $module->description,
                    'version' => $module->version,
                    'author' => $module->author,
                    'icon' => $module->icon,
                    'is_active' => $module->is_active,
                    'is_core' => $module->is_core,
                    'has_settings' => Setting::where('module', $module->name)->exists(),
                    'dependencies' => $module->dependencies,
                    'exists_on_disk' => $module->existsOnDisk(),
                ];
            });

        return Inertia::render('Admin/Modules/Index', [
            'modules' => $modules,
        ]);
    }

    /**
     * Szczegóły modułu
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
            return redirect()->route('admin.modules.index')->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Zainstaluj moduł z pliku ZIP
     */
    public function install(Request $request)
    {
        $request->validate([
            'module_file' => 'required|file|mimes:zip|max:50000',
        ]);

        $file = $request->file('module_file');
        $result = $this->moduleService->installFromZip($file->getPathname());

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    /**
     * Generuj nowy moduł
     */
    public function generate(Request $request)
    {
        $request->validate([
            'name' => 'required|string|alpha|min:3|max:50',
            'display_name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:50',
        ]);

        $result = $this->moduleService->generateModule(
            $request->name,
            $request->only(['display_name', 'description', 'icon'])
        );

        if ($result['success']) {
            return back()->with('success', $result['message']);
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
