<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_automation_sends', function (Blueprint $table): void {
            $table->timestamp('appointment_start_at')->nullable();
            $table->unsignedTinyInteger('reminder_hours')->nullable();
        });

        DB::table('whatsapp_automation_sends')
            ->where('type', 'reminder')
            ->where('status', 'pending')
            ->delete();

        DB::table('whatsapp_automation_sends')
            ->where('type', 'reminder')
            ->update(['reminder_hours' => 24]);

        Schema::table('whatsapp_automation_sends', function (Blueprint $table): void {
            $table->dropUnique('wa_auto_sends_appointment_unique');
            $table->unique(
                ['whatsapp_automation_id', 'appointment_id', 'reminder_hours'],
                'wa_auto_sends_appt_hour_unique',
            );
        });

        DB::table('whatsapp_automations')
            ->where('type', 'reminder')
            ->update(['delay_value' => 24]);

        DB::table('whatsapp_automations')
            ->where('type', 'reminder')
            ->where('message_template', "Olá {nome}, sua lavagem {servico} é amanhã às {hora}.\n\nSe precisar remarcar: {link}")
            ->update([
                'message_template' => 'Olá {nome}, sua lavagem {servico} está marcada para {data} às {hora}.',
            ]);

        DB::table('whatsapp_automations')
            ->where('type', 'reminder')
            ->where('message_template', "Olá, {nome}! Lembrete do seu horário em {empresa}.\n\nServiço: {servico}\nData: {data}\nHorário: {hora}\n\nSe precisar remarcar: {link}")
            ->update([
                'message_template' => "Olá, {nome}! Lembrete do seu horário em {empresa}.\n\nServiço: {servico}\nData: {data}\nHorário: {hora}",
            ]);
    }

    public function down(): void
    {
        DB::table('whatsapp_automation_sends')
            ->where('type', 'reminder')
            ->where('reminder_hours', 12)
            ->delete();

        Schema::table('whatsapp_automation_sends', function (Blueprint $table): void {
            $table->dropUnique('wa_auto_sends_appt_hour_unique');
            $table->unique(['whatsapp_automation_id', 'appointment_id'], 'wa_auto_sends_appointment_unique');
            $table->dropColumn(['appointment_start_at', 'reminder_hours']);
        });
    }
};
