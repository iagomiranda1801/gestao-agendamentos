<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->boolean('tattoo_ai_enabled')->default(false);
            $table->text('tattoo_ai_prompt')->nullable();
        });
        Schema::table('financial_accounts', function (Blueprint $table): void {
            $table->string('pix_recipient_name')->nullable();
        });

        Schema::create('tattoo_ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_whatsapp_instance_id')->constrained('company_whats_app_instances')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tattoo_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_normalized', 32);
            $table->string('remote_jid');
            $table->string('status', 40)->default('collecting_information');
            $table->boolean('human_takeover')->default(false);
            $table->json('collected_data')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();
            $table->unique(['company_whatsapp_instance_id', 'phone_normalized'], 'tattoo_ai_instance_phone_unique');
            $table->index(['company_id', 'status']);
        });

        Schema::create('tattoo_ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tattoo_ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('provider_message_id')->nullable();
            $table->string('direction', 12);
            $table->string('status', 20)->default('received');
            $table->string('media_mime', 80)->nullable();
            $table->string('media_disk')->nullable();
            $table->string('media_path')->nullable();
            $table->text('body')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'provider_message_id']);
            $table->index(['tattoo_ai_conversation_id', 'created_at']);
        });

        Schema::create('tattoo_payment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tattoo_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tattoo_quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tattoo_ai_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('whatsapp_message_id');
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type', 80);
            $table->unsignedInteger('size_bytes');
            $table->json('analysis')->nullable();
            $table->string('receipt_analysis_status', 20)->default('pending');
            $table->string('payment_status', 20)->default('receipt_received');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'whatsapp_message_id']);
            $table->index(['company_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tattoo_payment_receipts');
        Schema::dropIfExists('tattoo_ai_messages');
        Schema::dropIfExists('tattoo_ai_conversations');
        Schema::table('financial_accounts', fn (Blueprint $table) => $table->dropColumn('pix_recipient_name'));
        Schema::table('company_scheduling_settings', fn (Blueprint $table) => $table->dropColumn(['tattoo_ai_enabled', 'tattoo_ai_prompt']));
    }
};
