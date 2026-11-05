<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistance_bot_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('assistance_bot_flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistance_bot_flow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('definition');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['assistance_bot_flow_id', 'number'], 'assistance_bot_version_unique');
        });
        Schema::create('assistance_bot_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_whatsapp_instance_id')->constrained('company_whats_app_instances', indexName: 'assistance_conversation_instance_fk')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assistance_bot_flow_version_id')->nullable()->constrained(indexName: 'assistance_conversation_version_fk')->restrictOnDelete();
            $table->string('phone_normalized', 32);
            $table->boolean('human_takeover')->default(false);
            $table->json('state')->nullable();
            $table->timestamps();
            $table->unique(['company_whatsapp_instance_id', 'phone_normalized'], 'assistance_bot_contact_unique');
        });
        Schema::create('assistance_bot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assistance_bot_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('provider_message_key', 64)->unique();
            $table->text('incoming');
            $table->text('reply')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('provider_reference')->nullable();
            $table->timestamps();
        });
        Schema::create('assistance_bot_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assistance_bot_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('command_key', 64)->unique();
            $table->string('protocol', 20)->unique();
            $table->string('kind', 20);
            $table->json('data');
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistance_bot_requests');
        Schema::dropIfExists('assistance_bot_messages');
        Schema::dropIfExists('assistance_bot_conversations');
        Schema::dropIfExists('assistance_bot_flow_versions');
        Schema::dropIfExists('assistance_bot_flows');
    }
};
