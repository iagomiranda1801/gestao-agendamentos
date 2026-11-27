<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tattoo_ai_messages', function (Blueprint $table): void {
            // audio = texto veio de uma nota de voz transcrita; audio_failed = não foi possível ouvir.
            $table->string('source', 20)->nullable()->after('media_mime');
            $table->unsignedSmallInteger('audio_seconds')->nullable()->after('source');
            $table->string('transcription_model', 120)->nullable()->after('audio_seconds');
            $table->unsignedInteger('transcription_input_tokens')->nullable()->after('transcription_model');
            $table->unsignedInteger('transcription_output_tokens')->nullable()->after('transcription_input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('tattoo_ai_messages', function (Blueprint $table): void {
            $table->dropColumn(['source', 'audio_seconds', 'transcription_model', 'transcription_input_tokens', 'transcription_output_tokens']);
        });
    }
};
