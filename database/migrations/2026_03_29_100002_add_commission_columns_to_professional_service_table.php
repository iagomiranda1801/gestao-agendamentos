<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professional_service', function (Blueprint $table) {
            $table->string('commission_type')->nullable()->after('is_active');
            $table->decimal('commission_value', 8, 4)->nullable()->after('commission_type');
        });
    }

    public function down(): void
    {
        Schema::table('professional_service', function (Blueprint $table) {
            $table->dropColumn(['commission_type', 'commission_value']);
        });
    }
};
