<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->boolean('notify_professional_by_email')->default(true)->after('whatsapp_confirmation_template');
            $table->boolean('notify_professional_by_whatsapp')->default(true)->after('notify_professional_by_email');
        });
    }

    public function down(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'notify_professional_by_email',
                'notify_professional_by_whatsapp',
            ]);
        });
    }
};
