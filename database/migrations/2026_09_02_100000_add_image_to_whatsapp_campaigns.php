<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('message_template');
            $table->string('image_disk')->nullable()->after('image_path');
            $table->string('image_mime')->nullable()->after('image_disk');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['image_path', 'image_disk', 'image_mime']);
        });
    }
};
