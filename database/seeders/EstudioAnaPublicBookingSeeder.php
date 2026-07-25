<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Services\Scheduling\CompanySchedulingSettingService;
use Illuminate\Database\Seeder;

class EstudioAnaPublicBookingSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('slug', 'estudio-ana')->first();

        if (! $company) {
            return;
        }

        app(CompanySchedulingSettingService::class)->update($company, [
            'public_booking_enabled' => true,
            'online_auto_confirm' => false,
            'require_email_for_online_booking' => false,
            'allow_public_cancellation' => true,
            'allow_public_reschedule' => true,
            'allow_professional_selection' => true,
            'allow_no_professional_preference' => false,
            'show_service_price' => true,
            'show_service_duration' => true,
            'minimum_advance_minutes' => 120,
            'maximum_advance_days' => 60,
            'cancellation_minimum_advance_minutes' => 720,
            'reschedule_minimum_advance_minutes' => 720,
            'booking_page_title' => 'Agende seu horário',
            'booking_page_description' => 'Escolha o serviço, a data e o melhor horário para você.',
            'booking_confirmation_message' => 'Seu pedido de agendamento foi recebido. Aguarde a confirmação do estúdio.',
            'booking_primary_color' => '#7c3aed',
        ]);
    }
}
