<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tattoo_payment_receipts', function (Blueprint $table): void {
            $table->timestamp('artist_notified_at')->nullable();
            $table->string('reviewed_via', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tattoo_payment_receipts', fn (Blueprint $table) => $table->dropColumn(['artist_notified_at', 'reviewed_via']));
    }
};
