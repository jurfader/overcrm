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
        Schema::table('clients', function (Blueprint $table) {
            $table->string('client_status', 50)->nullable()->after('status')->comment('Status biznesowy: stripsiak, test, allegro itp.');
            $table->foreignId('assigned_to')->nullable()->after('created_by')->constrained('users')->nullOnDelete()->comment('Opiekun handlowy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
            $table->dropColumn(['client_status', 'assigned_to']);
        });
    }
};
