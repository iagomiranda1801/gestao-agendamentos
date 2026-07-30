<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table): void {
            $table->json('selected_client_ids')->nullable()->after('audience_type');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table): void {
            $table->dropColumn('selected_client_ids');
        });
    }
};
