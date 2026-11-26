<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_order_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('receipt_paper_mm')->default(80);
        });
    }

    public function down(): void
    {
        Schema::table('company_order_settings', function (Blueprint $table): void {
            $table->dropColumn('receipt_paper_mm');
        });
    }
};
