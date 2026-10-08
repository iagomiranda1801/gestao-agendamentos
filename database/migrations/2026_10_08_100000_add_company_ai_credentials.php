<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->string('ai_provider', 20)->nullable();
            $table->string('ai_model', 120)->nullable();
            $table->text('ai_api_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_scheduling_settings', fn (Blueprint $table) => $table->dropColumn(['ai_provider', 'ai_model', 'ai_api_key']));
    }
};
