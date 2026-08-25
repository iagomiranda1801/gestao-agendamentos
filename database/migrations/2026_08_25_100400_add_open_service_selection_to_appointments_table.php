<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('service_selection_mode')->default('defined')->after('service_id');
            $table->string('appointment_reason')->nullable()->after('reference_key');
            $table->foreignId('service_id')->nullable()->change();
            $table->string('service_name_snapshot')->nullable()->change();
            $table->decimal('price_snapshot', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn(['service_selection_mode', 'appointment_reason']);
            $table->foreignId('service_id')->nullable(false)->change();
            $table->string('service_name_snapshot')->nullable(false)->change();
            $table->decimal('price_snapshot', 12, 2)->nullable(false)->change();
        });
    }
};
