<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_whatsapp_instance_id')
                ->constrained('company_whats_app_instances')
                ->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable();
            $table->string('name')->nullable();
            $table->string('phone');
            $table->string('phone_normalized');
            $table->text('profile_picture_url')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('imported_as_client_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['company_whatsapp_instance_id', 'phone_normalized'],
                'whatsapp_contacts_instance_phone_unique',
            );
            $table->index(['company_id', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_contacts');
    }
};
