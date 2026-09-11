<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_bot_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_whatsapp_instance_id')
                ->nullable()
                ->constrained('company_whats_app_instances')
                ->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_normalized', 32);
            $table->string('remote_jid')->nullable();
            $table->string('state', 40);
            $table->json('data')->nullable();
            $table->string('last_incoming_message_id')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('finished_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'phone_normalized', 'finished_at'], 'wa_bot_conv_active_idx');
            $table->index(['company_id', 'last_activity_at']);
            $table->index(['last_incoming_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_bot_conversations');
    }
};
