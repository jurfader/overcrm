<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 50)->nullable()->index();
            $table->string('nip', 20)->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('source', 30)->default('manual');
            $table->foreignId('status_id')->constrained('lead_statuses');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('converted_to_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
