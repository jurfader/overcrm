<?php

namespace App\Console\Commands;

use App\Services\ModuleService;
use Illuminate\Console\Command;

class MakeModule extends Command
{
    protected $signature = 'make:module 
                            {name : Nazwa modułu (np. Invoices)}
                            {--display= : Wyświetlana nazwa modułu}
                            {--description= : Opis modułu}
                            {--icon=puzzle : Ikona modułu}
                            {--author=OVERMEDIA : Autor modułu}
                            {--with-model= : Utwórz model z podaną nazwą}
                            {--with-migration : Utwórz przykładową migrację}';

    protected $description = 'Generuje nowy moduł z pełną strukturą';

    public function handle(ModuleService $moduleService): int
    {
        $name = $this->argument('name');
        
        $this->info("🚀 Generowanie modułu: {$name}");
        $this->newLine();

        $options = [
            'display_name' => $this->option('display') ?? ucfirst($name),
            'description' => $this->option('description') ?? "Moduł {$name}",
            'icon' => $this->option('icon'),
            'author' => $this->option('author'),
        ];

        $result = $moduleService->generateModule($name, $options);

        if (!$result['success']) {
            $this->error("❌ Błąd: {$result['message']}");
            return Command::FAILURE;
        }

        // Dodatkowe opcje
        if ($modelName = $this->option('with-model')) {
            $this->createModel($name, $modelName);
        }

        if ($this->option('with-migration')) {
            $this->createMigration($name, $modelName ?? $name);
        }

        $this->displaySuccess($name, $result['path']);

        return Command::SUCCESS;
    }

    protected function createModel(string $moduleName, string $modelName): void
    {
        $modulePath = base_path("modules/{$moduleName}");
        $modelPath = "{$modulePath}/src/Models/{$modelName}.php";

        $content = <<<PHP
<?php

namespace Modules\\{$moduleName}\\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class {$modelName} extends Model
{
    use HasFactory;

    protected \$fillable = [
        // Dodaj pola do wypełnienia
    ];

    protected \$casts = [
        // Dodaj rzutowanie typów
    ];
}
PHP;

        file_put_contents($modelPath, $content);
        $this->info("  ✓ Utworzono model: {$modelName}");
    }

    protected function createMigration(string $moduleName, string $modelName): void
    {
        $modulePath = base_path("modules/{$moduleName}");
        $tableName = strtolower($modelName) . 's';
        $timestamp = date('Y_m_d_His');
        $migrationPath = "{$modulePath}/database/migrations/{$timestamp}_create_{$tableName}_table.php";

        $content = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->id();
            \$table->string('name');
            // Dodaj więcej kolumn tutaj
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
PHP;

        file_put_contents($migrationPath, $content);
        $this->info("  ✓ Utworzono migrację: create_{$tableName}_table");
    }

    protected function displaySuccess(string $name, string $path): void
    {
        $this->newLine();
        $this->info("✅ Moduł '{$name}' został utworzony!");
        $this->newLine();
        
        $this->line("📁 Struktura modułu:");
        $this->line("   {$path}/");
        $this->line("   ├── module.json           - Manifest modułu");
        $this->line("   ├── {$name}ServiceProvider.php");
        $this->line("   ├── config/               - Konfiguracja");
        $this->line("   ├── database/");
        $this->line("   │   ├── migrations/       - Migracje bazy danych");
        $this->line("   │   └── seeders/          - Seedery");
        $this->line("   ├── routes/");
        $this->line("   │   ├── web.php           - Routy webowe");
        $this->line("   │   └── api.php           - Routy API");
        $this->line("   ├── src/");
        $this->line("   │   ├── Controllers/      - Kontrolery");
        $this->line("   │   ├── Models/           - Modele Eloquent");
        $this->line("   │   └── Services/         - Serwisy biznesowe");
        $this->line("   └── resources/");
        $this->line("       └── js/");
        $this->line("           ├── Pages/        - Komponenty Vue");
        $this->line("           └── Components/   - Współdzielone komponenty");
        
        $this->newLine();
        $this->line("📋 Następne kroki:");
        $this->line("   1. Edytuj module.json - skonfiguruj uprawnienia i ustawienia");
        $this->line("   2. Aktywuj moduł w Panelu Administracyjnym");
        $this->line("   3. Uruchom: php artisan migrate (jeśli dodałeś migracje)");
        $this->line("   4. Uruchom: npm run build");
        $this->newLine();
    }
}
