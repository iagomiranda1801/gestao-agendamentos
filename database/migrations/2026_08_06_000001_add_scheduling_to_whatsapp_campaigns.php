<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable()->after('cancelled_at');
            $table->index(['company_id', 'status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'status', 'scheduled_at']);
            $table->dropColumn('scheduled_at');
        });
    }
};
