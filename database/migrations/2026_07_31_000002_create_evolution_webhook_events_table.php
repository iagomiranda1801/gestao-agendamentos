<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evolution_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event')->nullable()->index();
            $table->string('instance')->nullable()->index();
            $table->string('message_id')->nullable()->index();
            $table->string('provider_status')->nullable()->index();
            $table->string('remote_jid')->nullable();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evolution_webhook_events');
    }
};
