<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_order_settings', function (Blueprint $table): void {
            $table->boolean('dine_in_enabled')->default(false)->after('delivery_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('company_order_settings', function (Blueprint $table): void {
            $table->dropColumn('dine_in_enabled');
        });
    }
};
