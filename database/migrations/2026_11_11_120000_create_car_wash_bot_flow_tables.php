<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_wash_bot_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('car_wash_bot_flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_wash_bot_flow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('definition');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['car_wash_bot_flow_id', 'number'], 'car_wash_bot_version_unique');
        });
        Schema::create('car_wash_bot_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_whatsapp_instance_id')->constrained('company_whats_app_instances', indexName: 'car_wash_conversation_instance_fk')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('car_wash_bot_flow_version_id')->nullable()->constrained(indexName: 'car_wash_conversation_version_fk')->restrictOnDelete();
            $table->string('phone_normalized', 32);
            $table->boolean('human_takeover')->default(false);
            $table->json('state')->nullable();
            $table->timestamps();
            $table->unique(['company_whatsapp_instance_id', 'phone_normalized'], 'car_wash_bot_contact_unique');
        });
        Schema::create('car_wash_bot_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_wash_bot_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('provider_message_key', 64)->unique();
            $table->text('incoming');
            $table->text('reply')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('provider_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_wash_bot_messages');
        Schema::dropIfExists('car_wash_bot_conversations');
        Schema::dropIfExists('car_wash_bot_flow_versions');
        Schema::dropIfExists('car_wash_bot_flows');
    }
};
