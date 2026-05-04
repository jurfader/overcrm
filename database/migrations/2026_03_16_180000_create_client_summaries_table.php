<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_visit_id')->nullable()->constrained('client_visits')->nullOnDelete();
            $table->text('summary');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['client_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_summaries');
    }
};
