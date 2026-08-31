<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('vehicle_plate', 8)->nullable()->after('notes');
            $table->string('vehicle_model', 120)->nullable()->after('vehicle_plate');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['vehicle_plate', 'vehicle_model']);
        });
    }
};
