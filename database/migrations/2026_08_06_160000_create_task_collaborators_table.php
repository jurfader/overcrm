<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Współpracownicy zadania — osoby poza wykonawcą i autorem, które mają je widzieć
 * i móc nad nim pracować.
 *
 * Tabela jest warunkiem wdrożenia widoczności zadań: bez niej jedynym sposobem
 * na dopuszczenie kogoś do zadania byłoby przepisanie go na tę osobę, co gubi
 * informację, kto właściwie za nie odpowiada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_collaborators');
    }
};
