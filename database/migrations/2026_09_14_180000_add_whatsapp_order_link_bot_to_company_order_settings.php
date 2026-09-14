<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_order_settings', function (Blueprint $table): void {
            $table->boolean('whatsapp_order_link_bot_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('company_order_settings', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_order_link_bot_enabled');
        });
    }
};
