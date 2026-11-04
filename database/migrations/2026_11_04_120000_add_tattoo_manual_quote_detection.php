<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->string('tattoo_artist_notify_phone', 32)->nullable();
            $table->decimal('tattoo_fixed_deposit_amount', 12, 2)->nullable();
        });

        Schema::create('tattoo_manual_quote_detections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tattoo_ai_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tattoo_quote_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_normalized', 32);
            $table->string('customer_name', 120)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('message_text');
            $table->decimal('total', 12, 2);
            $table->decimal('deposit', 12, 2)->nullable();
            $table->boolean('deposit_written')->default(false);
            $table->json('candidates')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'phone_normalized']);
            $table->unique(['company_id', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tattoo_manual_quote_detections');
        Schema::table('company_scheduling_settings', fn (Blueprint $table) => $table->dropColumn([
            'tattoo_artist_notify_phone', 'tattoo_fixed_deposit_amount',
        ]));
    }
};
