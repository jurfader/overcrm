<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();          // np. ZAM/2026/05/001
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // kto utworzył
            $table->string('status', 30)->default('draft');  // draft|new|in_progress|completed|cancelled
            $table->date('order_date');
            $table->date('delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total_net', 12, 2)->default(0);
            $table->decimal('total_vat', 12, 2)->default(0);
            $table->decimal('total_gross', 12, 2)->default(0);
            $table->json('snapshot_company')->nullable();    // dane firmy w momencie wystawienia (NIP/REGON/adres) — żeby PDF był stabilny
            $table->json('snapshot_client')->nullable();     // dane klienta w momencie wystawienia
            $table->softDeletes();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index('order_date');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 200);                     // snapshot z products (lub free text)
            $table->string('sku', 60)->nullable();
            $table->string('unit', 20)->default('szt');
            $table->decimal('quantity', 12, 3);
            $table->decimal('price_net', 12, 2);
            $table->unsignedTinyInteger('vat_rate');
            $table->decimal('total_net', 12, 2);
            $table->decimal('total_vat', 12, 2);
            $table->decimal('total_gross', 12, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
