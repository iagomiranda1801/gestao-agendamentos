<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0)->after('duration_minutes');
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0)->after('buffer_before_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['buffer_before_minutes', 'buffer_after_minutes']);
        });
    }
};
