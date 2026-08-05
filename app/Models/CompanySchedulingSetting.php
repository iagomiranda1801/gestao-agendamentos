<?php

namespace App\Models;

use App\Enums\Weekday;
use Database\Factories\CompanySchedulingSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'slot_interval_minutes',
    'calendar_start_time',
    'calendar_end_time',
    'week_starts_on',
    'default_calendar_view',
    'allow_employee_self_view',
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
    'whatsapp_notifications_enabled',
    'whatsapp_instance',
    'whatsapp_instance_token',
    'whatsapp_instance_status',
    'whatsapp_instance_qr_code',
    'whatsapp_instance_connected_at',
    'whatsapp_sender_phone',
    'whatsapp_confirmation_template',
    'notify_professional_by_email',
    'notify_professional_by_whatsapp',
])]
class CompanySchedulingSetting extends Model
{
    /** @use HasFactory<CompanySchedulingSettingFactory> */
    use HasFactory;

    protected $guarded = ['company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot_interval_minutes' => 'integer',
            'week_starts_on' => 'integer',
            'allow_employee_self_view' => 'boolean',
            'public_booking_enabled' => 'boolean',
            'online_auto_confirm' => 'boolean',
            'require_email_for_online_booking' => 'boolean',
            'allow_public_cancellation' => 'boolean',
            'allow_public_reschedule' => 'boolean',
            'allow_professional_selection' => 'boolean',
            'allow_no_professional_preference' => 'boolean',
            'show_service_price' => 'boolean',
            'show_service_duration' => 'boolean',
            'minimum_advance_minutes' => 'integer',
            'maximum_advance_days' => 'integer',
            'cancellation_minimum_advance_minutes' => 'integer',
            'reschedule_minimum_advance_minutes' => 'integer',
            'whatsapp_notifications_enabled' => 'boolean',
            'notify_professional_by_email' => 'boolean',
            'notify_professional_by_whatsapp' => 'boolean',
            'whatsapp_instance_token' => 'encrypted',
            'whatsapp_instance_connected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function weekStartsOnWeekday(): Weekday
    {
        return Weekday::from($this->week_starts_on);
    }
}
