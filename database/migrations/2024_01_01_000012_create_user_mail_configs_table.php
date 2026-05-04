<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_mail_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name')->default('Domyślne'); // Nazwa konfiguracji
            $table->string('mail_host');
            $table->integer('mail_port')->default(587);
            $table->string('mail_username');
            $table->text('mail_password'); // Zaszyfrowane
            $table->string('mail_encryption')->default('tls'); // tls, ssl, null
            $table->string('mail_from_address');
            $table->string('mail_from_name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });

        // Tabela logów wysłanych maili
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_mail_config_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('email_template_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('client_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('client_visit_id')->nullable()->constrained()->onDelete('set null');
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('subject');
            $table->longText('html_content');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_emails');
        Schema::dropIfExists('user_mail_configs');
    }
};
