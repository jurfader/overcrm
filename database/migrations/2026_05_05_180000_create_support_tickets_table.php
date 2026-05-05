<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->string('category', 40)->default('other'); // bug | question | feature | other
            $table->text('message');
            $table->boolean('attach_log')->default(true);
            $table->string('status', 20)->default('new'); // new | sent | failed
            $table->text('email_error')->nullable();
            $table->json('meta')->nullable(); // url, user_agent, license_status, app_version
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
