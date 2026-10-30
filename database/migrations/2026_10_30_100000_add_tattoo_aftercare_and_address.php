<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->string('tattoo_artist_name', 100)->nullable();
            $table->text('tattoo_aftercare_message')->nullable();
            $table->string('tattoo_studio_address', 500)->nullable();
            $table->text('tattoo_address_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('company_scheduling_settings', fn (Blueprint $table) => $table->dropColumn([
            'tattoo_artist_name', 'tattoo_aftercare_message', 'tattoo_studio_address', 'tattoo_address_message',
        ]));
    }
};
