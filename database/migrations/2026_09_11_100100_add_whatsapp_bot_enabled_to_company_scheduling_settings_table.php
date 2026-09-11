<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->boolean('whatsapp_bot_enabled')->default(false)->after('whatsapp_notifications_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_bot_enabled');
        });
    }
};
