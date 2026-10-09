<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tattoo_ai_conversations', function (Blueprint $table): void {
            $table->unsignedBigInteger('context_start_message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tattoo_ai_conversations', function (Blueprint $table): void {
            $table->dropColumn('context_start_message_id');
        });
    }
};
