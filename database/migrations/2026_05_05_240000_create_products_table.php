<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 60)->nullable()->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('category', 80)->nullable();
            $table->string('unit', 20)->default('szt'); // szt, kg, l, godz, m, m2, m3
            $table->decimal('price_net', 12, 2)->default(0);
            $table->unsignedTinyInteger('vat_rate')->default(23); // %
            $table->decimal('stock', 12, 3)->default(0); // 3 miejsca po przecinku dla wagowych
            $table->boolean('track_stock')->default(false); // false = usługa lub bezgraniczny
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index('category');
            $table->index('active');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
