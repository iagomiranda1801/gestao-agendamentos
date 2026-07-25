<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->boolean('public_booking_enabled')->default(false)->after('allow_employee_self_view');
            $table->boolean('online_auto_confirm')->default(false)->after('public_booking_enabled');
            $table->boolean('require_email_for_online_booking')->default(false)->after('online_auto_confirm');
            $table->boolean('allow_public_cancellation')->default(true)->after('require_email_for_online_booking');
            $table->boolean('allow_public_reschedule')->default(true)->after('allow_public_cancellation');
            $table->boolean('allow_professional_selection')->default(true)->after('allow_public_reschedule');
            $table->boolean('allow_no_professional_preference')->default(false)->after('allow_professional_selection');
            $table->boolean('show_service_price')->default(true)->after('allow_no_professional_preference');
            $table->boolean('show_service_duration')->default(true)->after('show_service_price');
            $table->unsignedInteger('minimum_advance_minutes')->default(120)->after('show_service_duration');
            $table->unsignedSmallInteger('maximum_advance_days')->default(60)->after('minimum_advance_minutes');
            $table->unsignedInteger('cancellation_minimum_advance_minutes')->default(720)->after('maximum_advance_days');
            $table->unsignedInteger('reschedule_minimum_advance_minutes')->default(720)->after('cancellation_minimum_advance_minutes');
            $table->string('booking_page_title', 120)->nullable()->after('reschedule_minimum_advance_minutes');
            $table->text('booking_page_description')->nullable()->after('booking_page_title');
            $table->text('booking_confirmation_message')->nullable()->after('booking_page_description');
            $table->string('booking_primary_color', 7)->nullable()->after('booking_confirmation_message');
            $table->text('privacy_notice')->nullable()->after('booking_primary_color');
            $table->text('booking_terms')->nullable()->after('privacy_notice');
        });
    }

    public function down(): void
    {
        Schema::table('company_scheduling_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'public_booking_enabled',
                'online_auto_confirm',
                'require_email_for_online_booking',
                'allow_public_cancellation',
                'allow_public_reschedule',
                'allow_professional_selection',
                'allow_no_professional_preference',
                'show_service_price',
                'show_service_duration',
                'minimum_advance_minutes',
                'maximum_advance_days',
                'cancellation_minimum_advance_minutes',
                'reschedule_minimum_advance_minutes',
                'booking_page_title',
                'booking_page_description',
                'booking_confirmation_message',
                'booking_primary_color',
                'privacy_notice',
                'booking_terms',
            ]);
        });
    }
};
