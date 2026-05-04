<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tabela uprawnień
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Nazwa uprawnienia
            $table->string('code')->unique(); // Kod (np. clients_view, tasks_manage)
            $table->string('module'); // Moduł (clients, tasks, users, statuses, settings)
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Tabela powiązań użytkownik-uprawnienie
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            
            $table->unique(['user_id', 'permission_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('permissions');
    }
};
