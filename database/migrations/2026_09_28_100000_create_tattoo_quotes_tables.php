<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tattoo_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('professional_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_bot_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('manual');
            $table->string('status', 32)->default('awaiting_review');
            $table->text('description');
            $table->string('body_placement');
            $table->string('size_description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('tattoo_request_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tattoo_request_id')->constrained()->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type', 80);
            $table->unsignedInteger('size_bytes');
            $table->string('original_name')->nullable();
            $table->string('whatsapp_message_id')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'whatsapp_message_id']);
        });

        Schema::create('tattoo_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tattoo_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('price_type', 16);
            $table->decimal('amount_min', 12, 2);
            $table->decimal('amount_max', 12, 2)->nullable();
            $table->unsignedSmallInteger('sessions')->default(1);
            $table->unsignedSmallInteger('minutes_per_session')->nullable();
            $table->decimal('deposit_amount', 12, 2)->nullable();
            $table->date('valid_until')->nullable();
            $table->text('conditions')->nullable();
            $table->text('message_snapshot');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['tattoo_request_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tattoo_quotes');
        Schema::dropIfExists('tattoo_request_images');
        Schema::dropIfExists('tattoo_requests');
    }
};
