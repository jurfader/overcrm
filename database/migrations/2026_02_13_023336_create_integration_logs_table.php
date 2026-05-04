<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service', 50); // fakturownia, apilo, gus
            $table->string('method', 10)->default('GET'); // GET, POST, PUT, DELETE
            $table->string('endpoint'); // URL lub ścieżka API
            $table->json('request_data')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable(); // HTTP status code
            $table->text('response_summary')->nullable(); // Skrót odpowiedzi
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 20)->default('success'); // success, error
            $table->text('error_message')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('service');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
