<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->boolean('beauty_ai_enabled')->default(false);
            $table->text('beauty_ai_prompt')->nullable();
        });

        Schema::table('tattoo_ai_conversations', function (Blueprint $table): void {
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tattoo_ai_conversations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('appointment_id');
        });

        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->dropColumn(['beauty_ai_enabled', 'beauty_ai_prompt']);
        });
    }
};
