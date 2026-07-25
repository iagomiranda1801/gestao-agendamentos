<?php

namespace Database\Seeders;

use App\Enums\Weekday;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\CompanyBusinessHoursService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\Scheduling\ProfessionalBreakService;
use App\Services\Scheduling\ProfessionalWorkingHoursService;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

class EstudioAnaScheduleSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('slug', 'estudio-ana')->first();

        if (! $company) {
            return;
        }

        $ana = Professional::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Ana')
            ->first();

        if (! $ana) {
            throw new RuntimeException('Profissional Ana não encontrada para seed da agenda.');
        }

        $user = User::query()->where('email', 'ana@estudioana.test')->first()
            ?? User::query()->where('email', 'superadmin@imsolucoes.test')->first();

        if (! $user) {
            throw new RuntimeException('Usuário seed não encontrado para agendamentos.');
        }

        app(CompanySchedulingSettingService::class)->update($company, [
            'slot_interval_minutes' => 15,
            'calendar_start_time' => '07:00:00',
            'calendar_end_time' => '20:00:00',
            'week_starts_on' => Weekday::Monday->value,
            'default_calendar_view' => 'timeGridWeek',
        ]);

        $businessHours = app(CompanyBusinessHoursService::class);
        $businessHours->replaceWeeklyHours($company, [
            ['weekday' => Weekday::Monday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Tuesday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Wednesday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Thursday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Friday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Saturday->value, 'start_time' => '08:00:00', 'end_time' => '12:00:00'],
        ]);

        $workingHours = app(ProfessionalWorkingHoursService::class);
        $workingHours->replaceWeekly($company, $ana, [
            ['weekday' => Weekday::Monday->value, 'start_time' => '09:00:00', 'end_time' => '12:00:00'],
            ['weekday' => Weekday::Monday->value, 'start_time' => '13:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Tuesday->value, 'start_time' => '09:00:00', 'end_time' => '12:00:00'],
            ['weekday' => Weekday::Tuesday->value, 'start_time' => '13:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Wednesday->value, 'start_time' => '09:00:00', 'end_time' => '12:00:00'],
            ['weekday' => Weekday::Wednesday->value, 'start_time' => '13:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Thursday->value, 'start_time' => '09:00:00', 'end_time' => '12:00:00'],
            ['weekday' => Weekday::Thursday->value, 'start_time' => '13:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Friday->value, 'start_time' => '09:00:00', 'end_time' => '12:00:00'],
            ['weekday' => Weekday::Friday->value, 'start_time' => '13:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Saturday->value, 'start_time' => '09:00:00', 'end_time' => '12:00:00'],
        ]);

        app(ProfessionalBreakService::class)->replaceWeekly($company, $ana, [
            [
                'name' => 'Almoço',
                'weekday' => Weekday::Monday->value,
                'start_time' => '12:00:00',
                'end_time' => '13:00:00',
            ],
            [
                'name' => 'Almoço',
                'weekday' => Weekday::Tuesday->value,
                'start_time' => '12:00:00',
                'end_time' => '13:00:00',
            ],
            [
                'name' => 'Almoço',
                'weekday' => Weekday::Wednesday->value,
                'start_time' => '12:00:00',
                'end_time' => '13:00:00',
            ],
            [
                'name' => 'Almoço',
                'weekday' => Weekday::Thursday->value,
                'start_time' => '12:00:00',
                'end_time' => '13:00:00',
            ],
            [
                'name' => 'Almoço',
                'weekday' => Weekday::Friday->value,
                'start_time' => '12:00:00',
                'end_time' => '13:00:00',
            ],
        ]);

        $clients = Client::query()->where('company_id', $company->getKey())->limit(3)->get();
        $services = Service::query()->where('company_id', $company->getKey())->limit(3)->get();

        if ($clients->count() < 3 || $services->count() < 3) {
            throw new RuntimeException('Clientes ou serviços insuficientes para seed da agenda.');
        }

        $appointmentService = app(AppointmentService::class);
        $keys = [
            'seed:estudio-ana:appointment-1',
            'seed:estudio-ana:appointment-2',
            'seed:estudio-ana:appointment-3',
        ];

        foreach ($keys as $index => $referenceKey) {
            if (Appointment::query()
                ->where('company_id', $company->getKey())
                ->where('reference_key', $referenceKey)
                ->exists()) {
                continue;
            }

            $service = $services[$index];
            $slot = $this->findNextAvailableSlot($company, $ana, $service);

            $appointmentService->createInternalAppointment(
                $company,
                $user,
                $clients[$index],
                $ana,
                $service,
                $slot,
                ['reference_key' => $referenceKey],
            );
        }
    }

    protected function findNextAvailableSlot(Company $company, Professional $professional, Service $service): CarbonImmutable
    {
        $availability = app(AvailabilityService::class);
        $date = CompanyDateTime::nowLocal($company)->addDay();

        for ($day = 0; $day < 21; $day++) {
            if ($date->dayOfWeek === Weekday::Sunday->value) {
                $date = $date->addDay();

                continue;
            }

            $slots = $availability->getAvailableSlots($company, $professional, $service, $date);

            if ($slots->isNotEmpty()) {
                return $slots->first();
            }

            $date = $date->addDay();
        }

        throw new RuntimeException('Não foi possível encontrar horários disponíveis para seed da agenda.');
    }

    /**
     * @return list<CarbonImmutable>
     */
    protected function findFutureSlots(Company $company, Professional $professional, Service $service, int $count): array
    {
        $availability = app(AvailabilityService::class);
        $date = CompanyDateTime::nowLocal($company)->addDay();
        $found = [];

        for ($day = 0; $day < 21 && count($found) < $count; $day++) {
            if ($date->dayOfWeek === Weekday::Sunday->value) {
                $date = $date->addDay();

                continue;
            }

            $slots = $availability->getAvailableSlots($company, $professional, $service, $date);

            foreach ($slots as $slot) {
                $found[] = $slot;

                if (count($found) >= $count) {
                    break 2;
                }
            }

            $date = $date->addDay();
        }

        if (count($found) < $count) {
            throw new RuntimeException('Não foi possível encontrar horários disponíveis para seed da agenda.');
        }

        return $found;
    }
}
