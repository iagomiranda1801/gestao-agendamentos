<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('tattoo_self_service_min_hours')->nullable();
            $table->string('tattoo_cancel_deposit_policy', 16)->default('reter');
            $table->unsignedSmallInteger('tattoo_refund_cutoff_hours')->nullable();
        });
        Schema::table('tattoo_quotes', function (Blueprint $table): void {
            $table->string('deposit_outcome', 16)->nullable();
            $table->timestamp('deposit_outcome_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tattoo_quotes', fn (Blueprint $table) => $table->dropColumn(['deposit_outcome', 'deposit_outcome_at']));
        Schema::table('company_scheduling_settings', fn (Blueprint $table) => $table->dropColumn([
            'tattoo_self_service_min_hours', 'tattoo_cancel_deposit_policy', 'tattoo_refund_cutoff_hours',
        ]));
    }
};
