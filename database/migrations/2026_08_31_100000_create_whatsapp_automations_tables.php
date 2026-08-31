<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_automations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('delay_value');
            $table->unsignedInteger('cooldown_days')->default(30);
            $table->time('quiet_hours_start')->default('08:00:00');
            $table->time('quiet_hours_end')->default('20:00:00');
            $table->text('message_template');
            $table->timestamps();

            $table->unique(['company_id', 'type']);
            $table->index(['company_id', 'is_enabled']);
        });

        Schema::create('whatsapp_automation_sends', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_automation_id')->constrained('whatsapp_automations')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('phone')->nullable();
            $table->text('message_snapshot')->nullable();
            $table->string('status', 32);
            $table->string('skip_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'type', 'status']);
            $table->index(['whatsapp_automation_id', 'client_id']);
            $table->unique(['whatsapp_automation_id', 'appointment_id'], 'wa_auto_sends_appointment_unique');
            $table->unique(['whatsapp_automation_id', 'attendance_id'], 'wa_auto_sends_attendance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_automation_sends');
        Schema::dropIfExists('whatsapp_automations');
    }
};
