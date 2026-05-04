<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_mail_configs', function (Blueprint $table) {
            $table->string('imap_host')->nullable()->after('mail_encryption');
            $table->integer('imap_port')->nullable()->after('imap_host');
            $table->string('imap_encryption')->nullable()->after('imap_port'); // ssl, tls, null
        });
    }

    public function down(): void
    {
        Schema::table('user_mail_configs', function (Blueprint $table) {
            $table->dropColumn(['imap_host', 'imap_port', 'imap_encryption']);
        });
    }
};
