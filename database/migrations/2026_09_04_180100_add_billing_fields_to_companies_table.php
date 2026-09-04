<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('billing_interval')->nullable()->after('subscription_status');
            $table->timestamp('current_period_end')->nullable()->after('billing_interval');
            $table->unsignedInteger('quoted_price_cents')->nullable()->after('current_period_end');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['billing_interval', 'current_period_end', 'quoted_price_cents']);
        });
    }
};
