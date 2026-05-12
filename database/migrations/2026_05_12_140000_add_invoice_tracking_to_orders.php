<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pola sledzenia zewnetrznej faktury na zamowieniu (InvoiceProvider:
 * inFakt, Fakturownia, Apilo, ...). Aktualizowane przez webhooks providerow.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('invoice_provider', 32)->nullable()->after('snapshot_client');
            $table->string('invoice_external_id', 64)->nullable()->after('invoice_provider')->index();
            $table->string('invoice_number', 64)->nullable()->after('invoice_external_id');
            $table->timestamp('invoice_paid_at')->nullable()->after('invoice_number');
            $table->string('invoice_ksef_status', 16)->nullable()->after('invoice_paid_at'); // sent | success | error
            $table->string('invoice_ksef_number', 64)->nullable()->after('invoice_ksef_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_provider',
                'invoice_external_id',
                'invoice_number',
                'invoice_paid_at',
                'invoice_ksef_status',
                'invoice_ksef_number',
            ]);
        });
    }
};
