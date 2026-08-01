<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table): void {
            $table->string('email_snapshot')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table): void {
            $table->dropColumn('email_snapshot');
        });
    }
};
