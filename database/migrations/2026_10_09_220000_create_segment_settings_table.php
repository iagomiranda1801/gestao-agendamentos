<?php

use App\Models\SegmentSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segment_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('segment')->unique();
            $table->string('name')->nullable();
            $table->string('tagline')->nullable();
            $table->string('primary_color', 7)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('login_image_path')->nullable();
            $table->string('logo_height')->nullable();
            $table->json('login')->nullable();
            $table->timestamps();
        });

        SegmentSetting::syncMissing();
    }

    public function down(): void
    {
        Schema::dropIfExists('segment_settings');
    }
};
