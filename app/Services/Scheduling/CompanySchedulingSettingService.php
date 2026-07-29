<?php

namespace App\Services\Scheduling;

use App\Models\Company;
use App\Models\CompanySchedulingSetting;
use App\Support\PhoneNormalizer;
use App\Support\PublicBookingTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanySchedulingSettingService
{
    public function getOrCreate(Company $company): CompanySchedulingSetting
    {
        $setting = CompanySchedulingSetting::query()
            ->where('company_id', $company->getKey())
            ->first();

        if ($setting) {
            return $setting;
        }

        $setting = new CompanySchedulingSetting([
            'slot_interval_minutes' => 15,
            'calendar_start_time' => '07:00:00',
            'calendar_end_time' => '22:00:00',
            'week_starts_on' => 1,
            'default_calendar_view' => 'timeGridWeek',
            'allow_employee_self_view' => true,
        ]);
        $setting->company()->associate($company);
        $setting->save();

        return $setting->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data): CompanySchedulingSetting
    {
        return DB::transaction(function () use ($company, $data): CompanySchedulingSetting {
            $setting = $this->getOrCreate($company);
            $payload = $this->preparePayload($data);

            $this->validatePayload(array_merge($setting->getAttributes(), $payload));

            $setting->fill($payload);
            $setting->save();

            return $setting->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        unset($data['company_id']);

        if (array_key_exists('booking_page_title', $data)) {
            $data['booking_page_title'] = PublicBookingTextSanitizer::bookingPageTitle($data['booking_page_title']);
        }

        if (array_key_exists('booking_page_description', $data)) {
            $data['booking_page_description'] = PublicBookingTextSanitizer::bookingPageDescription($data['booking_page_description']);
        }

        if (array_key_exists('booking_confirmation_message', $data)) {
            $data['booking_confirmation_message'] = PublicBookingTextSanitizer::bookingConfirmationMessage($data['booking_confirmation_message']);
        }

        if (array_key_exists('privacy_notice', $data)) {
            $data['privacy_notice'] = PublicBookingTextSanitizer::privacyNotice($data['privacy_notice']);
        }

        if (array_key_exists('booking_terms', $data)) {
            $data['booking_terms'] = PublicBookingTextSanitizer::bookingTerms($data['booking_terms']);
        }

        if (array_key_exists('whatsapp_instance', $data) && filled($data['whatsapp_instance'])) {
            $data['whatsapp_instance'] = trim((string) $data['whatsapp_instance']);
        }

        if (array_key_exists('whatsapp_sender_phone', $data)) {
            $data['whatsapp_sender_phone'] = PhoneNormalizer::normalize(
                filled($data['whatsapp_sender_phone']) ? (string) $data['whatsapp_sender_phone'] : null,
            );
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function validatePayload(array $payload): void
    {
        $allowedIntervals = [5, 10, 15, 20, 30, 60];

        if (isset($payload['slot_interval_minutes'])
            && ! in_array((int) $payload['slot_interval_minutes'], $allowedIntervals, true)) {
            throw ValidationException::withMessages([
                'slot_interval_minutes' => 'Intervalo de horários inválido.',
            ]);
        }

        if (isset($payload['calendar_start_time'], $payload['calendar_end_time'])
            && $payload['calendar_end_time'] <= $payload['calendar_start_time']) {
            throw ValidationException::withMessages([
                'calendar_end_time' => 'O horário final do calendário deve ser posterior ao horário inicial.',
            ]);
        }

        if (isset($payload['maximum_advance_days'])) {
            $days = (int) $payload['maximum_advance_days'];

            if ($days < 1 || $days > 365) {
                throw ValidationException::withMessages([
                    'maximum_advance_days' => 'A quantidade máxima de dias deve estar entre 1 e 365.',
                ]);
            }
        }

        foreach (['minimum_advance_minutes', 'cancellation_minimum_advance_minutes', 'reschedule_minimum_advance_minutes'] as $field) {
            if (isset($payload[$field]) && (int) $payload[$field] < 0) {
                throw ValidationException::withMessages([
                    $field => 'O valor não pode ser negativo.',
                ]);
            }
        }

        if (array_key_exists('booking_primary_color', $payload)
            && filled($payload['booking_primary_color'])
            && ! preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $payload['booking_primary_color'])) {
            throw ValidationException::withMessages([
                'booking_primary_color' => 'Informe uma cor hexadecimal válida.',
            ]);
        }

        if ((bool) ($payload['whatsapp_notifications_enabled'] ?? false)) {
            if (blank($payload['whatsapp_instance'] ?? null) && blank(config('services.evolution.instance'))) {
                throw ValidationException::withMessages([
                    'whatsapp_instance' => 'Informe a instância Evolution desta empresa ou configure EVOLUTION_INSTANCE no servidor.',
                ]);
            }

            if (blank($payload['whatsapp_sender_phone'] ?? null)) {
                throw ValidationException::withMessages([
                    'whatsapp_sender_phone' => 'Informe o número WhatsApp remetente da empresa.',
                ]);
            }
        }
    }
}
