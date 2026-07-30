<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_whats_app_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('instance_name')->nullable();
            $table->text('instance_token')->nullable();
            $table->string('sender_phone')->nullable();
            $table->string('status')->nullable();
            $table->longText('qr_code')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'is_default']);
            $table->unique(['company_id', 'instance_name'], 'company_wa_instance_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_whats_app_instances');
    }
};
