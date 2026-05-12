<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dodaje sip_account do users — wewn. numer SIP/extension uzywany przez Ringostat
 * jako 'from' w click-to-call. Fallback przy braku: users.play_phone.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'sip_account')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('sip_account', 64)->nullable()->after('play_phone')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'sip_account')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('sip_account');
            });
        }
    }
};
