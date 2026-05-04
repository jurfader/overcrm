<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->date('visit_date');
            $table->time('visit_time')->nullable();
            $table->string('title')->nullable();
            $table->text('notes')->nullable();
            $table->string('color', 7)->default('#3B82F6'); // hex color
            $table->enum('status', ['planned', 'confirmed', 'completed', 'cancelled'])->default('planned');
            $table->decimal('order_value', 12, 2)->nullable();
            $table->string('apilo_order_id')->nullable();
            $table->timestamps();
            
            $table->index(['visit_date']);
            $table->index(['client_id', 'visit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_visits');
    }
};
