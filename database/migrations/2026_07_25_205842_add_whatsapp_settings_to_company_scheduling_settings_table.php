<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->boolean('whatsapp_notifications_enabled')->default(false)->after('booking_terms');
            $table->string('whatsapp_instance')->nullable()->after('whatsapp_notifications_enabled');
            $table->text('whatsapp_confirmation_template')->nullable()->after('whatsapp_instance');
        });
    }

    public function down(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'whatsapp_notifications_enabled',
                'whatsapp_instance',
                'whatsapp_confirmation_template',
            ]);
        });
    }
};
