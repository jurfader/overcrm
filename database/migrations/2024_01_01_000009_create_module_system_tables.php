<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela modułów
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Nazwa techniczna (np. "crm", "invoices")
            $table->string('display_name'); // Nazwa wyświetlana
            $table->text('description')->nullable();
            $table->string('version')->default('1.0.0');
            $table->string('author')->nullable();
            $table->string('icon')->nullable(); // Nazwa ikony SVG
            $table->boolean('is_active')->default(false);
            $table->boolean('is_core')->default(false); // Czy moduł systemowy (nie można usunąć)
            $table->integer('order')->default(0);
            $table->json('dependencies')->nullable(); // Zależności od innych modułów
            $table->json('permissions')->nullable(); // Uprawnienia modułu
            $table->timestamps();
        });

        // Tabela ustawień (klucz-wartość z możliwością grupowania)
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('module')->default('core'); // Moduł do którego należy
            $table->string('group')->default('general'); // Grupa ustawień
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, boolean, integer, json, select, textarea
            $table->string('label')->nullable(); // Etykieta do wyświetlenia
            $table->text('description')->nullable();
            $table->json('options')->nullable(); // Opcje dla select
            $table->boolean('is_public')->default(false); // Czy widoczne publicznie
            $table->integer('order')->default(0);
            $table->timestamps();
            
            $table->unique(['module', 'key']);
        });

        // Tabela logów instalacji modułów
        Schema::create('module_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->onDelete('cascade');
            $table->string('action'); // installed, updated, activated, deactivated, uninstalled
            $table->string('version')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_logs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('modules');
    }
};
