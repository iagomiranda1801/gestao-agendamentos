<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_scheduling_settings', fn (Blueprint $table) => $table->boolean('tattoo_custom_flow_enabled')->default(false));
        Schema::table('appointments', fn (Blueprint $table) => $table->boolean('is_tattoo')->default(false));
        Schema::table('tattoo_ai_conversations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('menu_choice')->nullable();
            $table->timestamp('last_bot_question_at')->nullable();
            $table->timestamp('quote_followup_sent_at')->nullable();
        });
        Schema::table('whatsapp_bot_conversations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('menu_choice')->nullable();
            $table->timestamp('last_bot_question_at')->nullable();
            $table->timestamp('quote_followup_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_bot_conversations', fn (Blueprint $table) => $table->dropColumn(['menu_choice', 'last_bot_question_at', 'quote_followup_sent_at']));
        Schema::table('tattoo_ai_conversations', fn (Blueprint $table) => $table->dropColumn(['menu_choice', 'last_bot_question_at', 'quote_followup_sent_at']));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('is_tattoo'));
        Schema::table('company_scheduling_settings', fn (Blueprint $table) => $table->dropColumn('tattoo_custom_flow_enabled'));
    }
};
