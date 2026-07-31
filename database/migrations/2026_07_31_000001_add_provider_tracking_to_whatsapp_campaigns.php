<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table): void {
            $table->unsignedInteger('accepted_count')->default(0)->after('sent_count');
        });

        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table): void {
            $table->string('provider_message_id')->nullable()->after('error_message');
            $table->string('provider_status')->nullable()->after('provider_message_id');
            $table->json('provider_response')->nullable()->after('provider_status');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_message_id',
                'provider_status',
                'provider_response',
            ]);
        });

        Schema::table('whatsapp_campaigns', function (Blueprint $table): void {
            $table->dropColumn('accepted_count');
        });
    }
};
