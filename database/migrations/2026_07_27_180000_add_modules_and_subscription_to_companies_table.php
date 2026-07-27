<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('enabled_modules')->nullable()->after('is_active');
            $table->timestamp('trial_ends_at')->nullable()->after('enabled_modules');
            $table->string('subscription_status')->default('active')->after('trial_ends_at');
        });

        DB::table('companies')->update([
            'enabled_modules' => json_encode(['scheduling', 'stock', 'finance']),
            'subscription_status' => 'active',
        ]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['enabled_modules', 'trial_ends_at', 'subscription_status']);
        });
    }
};
