<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('public_confirmation_code', 20)->nullable()->after('reference_key');
            $table->string('client_name_snapshot')->nullable()->after('public_confirmation_code');
            $table->string('client_phone_snapshot', 30)->nullable()->after('client_name_snapshot');
            $table->string('client_email_snapshot')->nullable()->after('client_phone_snapshot');
            $table->dateTime('privacy_accepted_at')->nullable()->after('client_email_snapshot');
            $table->dateTime('terms_accepted_at')->nullable()->after('privacy_accepted_at');
            $table->dateTime('public_booked_at')->nullable()->after('terms_accepted_at');

            $table->unique(['company_id', 'public_confirmation_code'], 'appt_company_public_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropUnique('appt_company_public_code_unique');
            $table->dropColumn([
                'public_confirmation_code',
                'client_name_snapshot',
                'client_phone_snapshot',
                'client_email_snapshot',
                'privacy_accepted_at',
                'terms_accepted_at',
                'public_booked_at',
            ]);
        });
    }
};
