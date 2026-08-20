<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('source')->default('manual')->after('whatsapp_marketing_opt_in');
            $table->timestamp('source_imported_at')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['source', 'source_imported_at']);
        });
    }
};
